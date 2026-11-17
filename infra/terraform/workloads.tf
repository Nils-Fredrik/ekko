locals {
  worker_job_timeout_seconds = 420
  queue_retry_after_seconds  = 450

  jobs = var.enable_workloads ? {
    dispatch = { command = ["php", "artisan", "ekko:dispatch-due"], timeout = "3600s" }
    wake     = { command = ["php", "artisan", "ekko:dispatch-due", "--poll"], timeout = "3600s" }
    worker   = { command = ["sh", "-c", "pids=''; for i in 1 2 3 4 5 6 7 8; do php artisan queue:work --stop-when-empty --max-time=3300 --timeout=${local.worker_job_timeout_seconds} & pids=\"$pids $!\"; done; status=0; for pid in $pids; do wait $pid || status=1; done; exit $status"], timeout = "3900s" }
    task     = { command = ["sh", "-c"], timeout = "3600s" }
    migrate  = { command = ["php", "artisan", "migrate", "--force"], timeout = "3600s" }
  } : {}
  common_env = {
    APP_ENV                        = "production"
    APP_URL                        = var.app_url
    APP_DEBUG                      = "false"
    DB_CONNECTION                  = "pgsql"
    DB_HOST                        = "/cloudsql/${google_sql_database_instance.postgres.connection_name}"
    DB_PORT                        = "5432"
    DB_DATABASE                    = google_sql_database.app.name
    DB_RLS_ROLE                    = var.app_db_user
    DB_QUEUE_RETRY_AFTER           = tostring(local.queue_retry_after_seconds)
    QUEUE_CONNECTION               = "database"
    CACHE_STORE                    = "database"
    SESSION_DRIVER                 = "database"
    SESSION_COOKIE                 = "__Host-ekko_session"
    SESSION_SECURE_COOKIE          = "true"
    SESSION_SAME_SITE              = "lax"
    LOG_CHANNEL                    = "stderr"
    LOG_LEVEL                      = "info"
    EKKO_DISABLED_ENGINE_PROVIDERS = "perplexity,dataforseo"
    EKKO_GEMINI_GROUNDING_APPROVED = tostring(var.gemini_grounding_approved)
    GOOGLE_CLOUD_PROJECT           = var.project_id
    GOOGLE_CLOUD_REGION            = var.region
    EKKO_START_WORKER              = "true"
    EKKO_RAW_BUCKET                = google_storage_bucket.raw.name
    EKKO_REPORTS_BUCKET            = google_storage_bucket.reports.name
    # Laravel resolves REMOTE_ADDR per request; trust the fixed ALB hop as well.
    TRUSTED_PROXIES = var.enable_https ? "REMOTE_ADDR,${google_compute_global_address.web[0].address}" : "*"
  }
  web_secrets = {
    APP_KEY              = "app-key"
    DB_PASSWORD          = "db-password"
    GOOGLE_CLIENT_SECRET = "google-oauth-client-secret"
  }
  worker_secrets = {
    ANTHROPIC_API_KEY = "anthropic-api-key"
    OPENAI_API_KEY    = "openai-api-key"
    GEMINI_API_KEY    = "gemini-api-key"
  }
}

resource "google_cloud_run_v2_service" "web" {
  count               = var.enable_workloads ? 1 : 0
  name                = "ekko-web"
  location            = var.region
  ingress             = "INGRESS_TRAFFIC_ALL"
  deletion_protection = true

  # The API materializes this block on creation; declare it to avoid perpetual drift.
  scaling {
    min_instance_count = 0
  }

  template {
    service_account = google_service_account.component["web"].email
    scaling {
      min_instance_count = 0
      max_instance_count = 4
    }
    containers {
      image = var.image
      resources { limits = { cpu = "1", memory = "1Gi" } }
      # /up fails when the database login role could bypass row-level security (T39-F2,
      # App\Tenancy\Rls\DatabaseRoleCheck), so such a revision never receives traffic.
      startup_probe {
        http_get { path = "/up" }
        period_seconds    = 5
        timeout_seconds   = 3
        failure_threshold = 12
      }
      dynamic "env" {
        for_each = merge(local.common_env, { DB_USERNAME = var.app_db_user, GOOGLE_CLIENT_ID = var.google_oauth_client_id, GOOGLE_REDIRECT_URI = "${var.app_url}/auth/google/callback", EKKO_SCHEDULER_SERVICE_ACCOUNT = google_service_account.component["scheduler"].email, EKKO_SCHEDULER_AUDIENCE = var.app_url })
        content {
          name  = env.key
          value = env.value
        }
      }
      dynamic "env" {
        for_each = local.web_secrets
        content {
          name = env.key
          value_source {
            secret_key_ref {
              secret  = google_secret_manager_secret.application[env.value].secret_id
              version = "latest"
            }
          }
        }
      }
      volume_mounts {
        name       = "cloudsql"
        mount_path = "/cloudsql"
      }
    }
    volumes {
      name = "cloudsql"
      cloud_sql_instance { instances = [google_sql_database_instance.postgres.connection_name] }
    }
  }
  lifecycle {
    # T10 deploys image revisions and manages traffic after bootstrap.
    ignore_changes = [client, client_version, template[0].containers[0].image, traffic]
    precondition {
      condition     = can(regex("^${var.region}-docker[.]pkg[.]dev/${var.project_id}/ekko/web@sha256:[a-f0-9]{64}$", var.image))
      error_message = "Set image to an immutable web digest in this project's Ekko Artifact Registry repository."
    }
    precondition {
      condition     = can(regex("^[0-9]+-[A-Za-z0-9_-]+[.]apps[.]googleusercontent[.]com$", var.google_oauth_client_id))
      error_message = "Create a Google Web OAuth client and set google_oauth_client_id before enabling workloads."
    }
    precondition {
      condition     = startswith(var.app_url, "https://")
      error_message = "Set app_url to the canonical HTTPS URL before enabling workloads."
    }
  }
  depends_on = [google_project_service.api, google_secret_manager_secret_iam_member.read, google_project_iam_member.cloudsql_client]
}

# The public service contains login pages. Internal tick routes must themselves
# verify the Scheduler's OIDC token; IAM alone cannot protect individual routes.
resource "google_cloud_run_v2_service_iam_member" "public" {
  count    = var.enable_workloads && var.enable_public_access ? 1 : 0
  location = var.region
  name     = google_cloud_run_v2_service.web[0].name
  role     = "roles/run.invoker"
  member   = "allUsers"
}

resource "google_cloud_run_v2_service_iam_member" "scheduler" {
  count    = var.enable_workloads ? 1 : 0
  location = var.region
  name     = google_cloud_run_v2_service.web[0].name
  role     = "roles/run.invoker"
  member   = "serviceAccount:${google_service_account.component["scheduler"].email}"
}

resource "google_cloud_run_v2_job" "job" {
  for_each            = local.jobs
  name                = "ekko-${each.key}"
  location            = var.region
  deletion_protection = true
  template {
    task_count  = 1
    parallelism = each.key == "worker" ? null : 1
    template {
      service_account = google_service_account.component[each.key == "wake" ? "dispatch" : each.key].email
      timeout         = each.value.timeout
      max_retries     = 0
      containers {
        image   = var.job_image
        command = each.value.command
        args    = each.key == "task" ? ["php artisan list"] : []
        resources { limits = { cpu = "1", memory = "1Gi" } }
        dynamic "env" {
          for_each = merge(
            local.common_env,
            { DB_USERNAME = each.key == "migrate" ? var.admin_db_user : var.app_db_user },
            each.key == "task" ? { EKKO_CLICKHOUSE_ENDPOINTS = join(",", var.clickhouse_endpoints) } : {},
          )
          content {
            name  = env.key
            value = env.value
          }
        }
        dynamic "env" {
          for_each = merge({ APP_KEY = "app-key", DB_PASSWORD = each.key == "migrate" ? "db-admin-password" : "db-password" }, each.key == "worker" ? local.worker_secrets : {})
          content {
            name = env.key
            value_source {
              secret_key_ref {
                secret  = google_secret_manager_secret.application[env.value].secret_id
                version = "latest"
              }
            }
          }
        }
        volume_mounts {
          name       = "cloudsql"
          mount_path = "/cloudsql"
        }
      }
      volumes {
        name = "cloudsql"
        cloud_sql_instance { instances = [google_sql_database_instance.postgres.connection_name] }
      }
    }
  }
  # T10 owns image updates after the initial provision.
  lifecycle {
    ignore_changes = [client, client_version, template[0].template[0].containers[0].image]
    precondition {
      condition     = can(regex("^${var.region}-docker[.]pkg[.]dev/${var.project_id}/ekko/jobs@sha256:[a-f0-9]{64}$", var.job_image))
      error_message = "Set job_image to an immutable jobs digest with Chromium in this project's Ekko Artifact Registry repository."
    }
  }
  depends_on = [google_project_service.api, google_secret_manager_secret_iam_member.read, google_project_iam_member.cloudsql_client]
}

# Only the dispatcher identity can start workers with task-count overrides.
resource "google_cloud_run_v2_job_iam_member" "worker_executor" {
  for_each = var.enable_workloads ? toset(["dispatch"]) : toset([])
  project  = var.project_id
  location = var.region
  name     = google_cloud_run_v2_job.job["worker"].name
  role     = "roles/run.jobsExecutorWithOverrides"
  member   = "serviceAccount:${google_service_account.component[each.key].email}"
}

resource "google_cloud_run_v2_job_iam_member" "scheduler_dispatch" {
  count    = var.enable_workloads ? 1 : 0
  project  = var.project_id
  location = var.region
  name     = google_cloud_run_v2_job.job["dispatch"].name
  role     = "roles/run.jobsExecutor"
  member   = "serviceAccount:${google_service_account.component["scheduler"].email}"
}

resource "google_cloud_run_v2_job_iam_member" "scheduler_task" {
  count    = var.enable_workloads ? 1 : 0
  project  = var.project_id
  location = var.region
  name     = google_cloud_run_v2_job.job["task"].name
  role     = "roles/run.jobsExecutorWithOverrides"
  member   = "serviceAccount:${google_service_account.component["scheduler"].email}"
}

# Read executions before starting a new worker. The executor role alone cannot list them.
resource "google_project_iam_custom_role" "worker_execution_reader" {
  role_id     = "ekkoWorkerExecutionReader"
  title       = "Ekko worker execution reader"
  permissions = ["run.executions.list"]
}

resource "google_cloud_run_v2_job_iam_member" "worker_execution_reader" {
  for_each = var.enable_workloads ? toset(["dispatch"]) : toset([])
  project  = var.project_id
  location = var.region
  name     = google_cloud_run_v2_job.job["worker"].name
  role     = google_project_iam_custom_role.worker_execution_reader.name
  member   = "serviceAccount:${google_service_account.component[each.key].email}"
}

# Web can invoke only a fixed poll dispatcher; no command, environment or task-count overrides.
resource "google_cloud_run_v2_job_iam_member" "web_wake" {
  count    = var.enable_workloads ? 1 : 0
  project  = var.project_id
  location = var.region
  name     = google_cloud_run_v2_job.job["wake"].name
  role     = "roles/run.jobsExecutor"
  member   = "serviceAccount:${google_service_account.component["web"].email}"
}

resource "google_cloud_run_v2_job_iam_member" "web_wake_reader" {
  count    = var.enable_workloads ? 1 : 0
  project  = var.project_id
  location = var.region
  name     = google_cloud_run_v2_job.job["wake"].name
  role     = google_project_iam_custom_role.worker_execution_reader.name
  member   = "serviceAccount:${google_service_account.component["web"].email}"
}
