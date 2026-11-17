locals {
  apis = toset([
    "analyticsdata.googleapis.com",
    "artifactregistry.googleapis.com",
    "cloudresourcemanager.googleapis.com",
    "clouderrorreporting.googleapis.com",
    "cloudscheduler.googleapis.com",
    "compute.googleapis.com",
    "certificatemanager.googleapis.com",
    "iam.googleapis.com",
    "iamcredentials.googleapis.com",
    "logging.googleapis.com",
    "monitoring.googleapis.com",
    "run.googleapis.com",
    "secretmanager.googleapis.com",
    "sqladmin.googleapis.com",
    "sts.googleapis.com",
  ])
  identities = toset(["web", "dispatch", "worker", "task", "migrate", "scheduler", "ci"])
  secrets = toset([
    "app-key",
    "db-password",
    "db-admin-password",
    "google-oauth-client-secret",
    "microsoft-oauth-client-secret",
    "anthropic-api-key",
    "openai-api-key",
    "gemini-api-key",
    "perplexity-api-key",
    "dataforseo-credentials",
  ])
}

resource "google_project_service" "api" {
  for_each           = local.apis
  service            = each.value
  disable_on_destroy = false
}

resource "google_service_account" "component" {
  for_each     = local.identities
  account_id   = "ekko-${each.key}"
  display_name = "Ekko ${each.key}"
  depends_on   = [google_project_service.api]
}

resource "google_artifact_registry_repository" "images" {
  location      = var.region
  repository_id = "ekko"
  description   = "Ekko application images"
  format        = "DOCKER"
  depends_on    = [google_project_service.api]
}

resource "google_artifact_registry_repository_iam_member" "ci_push" {
  location   = google_artifact_registry_repository.images.location
  repository = google_artifact_registry_repository.images.name
  role       = "roles/artifactregistry.writer"
  member     = "serviceAccount:${google_service_account.component["ci"].email}"
}

resource "google_sql_database_instance" "postgres" {
  name                = "ekko-postgres"
  region              = var.region
  database_version    = "POSTGRES_16"
  deletion_protection = true

  settings {
    edition               = "ENTERPRISE"
    tier                  = "db-g1-small"
    disk_type             = "PD_SSD"
    disk_size             = 20
    disk_autoresize       = true
    availability_type     = "ZONAL"
    connector_enforcement = "REQUIRED"
    ip_configuration {
      ipv4_enabled = true
      ssl_mode     = "ENCRYPTED_ONLY"
    }
    backup_configuration {
      enabled                        = true
      location                       = var.region
      point_in_time_recovery_enabled = true
      backup_retention_settings {
        retained_backups = 7
        retention_unit   = "COUNT"
      }
    }
  }
  depends_on = [google_project_service.api]
}

resource "google_sql_database" "app" {
  name     = "ekko"
  instance = google_sql_database_instance.postgres.name
}

resource "google_storage_bucket" "raw" {
  name                        = var.raw_bucket_name
  location                    = var.region
  uniform_bucket_level_access = true
  public_access_prevention    = "enforced"
  versioning { enabled = true }
  soft_delete_policy {
    retention_duration_seconds = 0
  }
  lifecycle_rule {
    action {
      type          = "SetStorageClass"
      storage_class = "NEARLINE"
    }
    condition {
      age                   = 90
      matches_storage_class = ["STANDARD"]
    }
  }
  lifecycle_rule {
    action { type = "Delete" }
    condition {
      age        = 400
      with_state = "LIVE"
    }
  }
  lifecycle_rule {
    action { type = "Delete" }
    condition {
      age        = 400
      with_state = "ARCHIVED"
    }
  }
  lifecycle { prevent_destroy = true }
}

resource "google_storage_bucket" "reports" {
  name                        = var.reports_bucket_name
  location                    = var.region
  uniform_bucket_level_access = true
  public_access_prevention    = "enforced"
  lifecycle { prevent_destroy = true }
}

resource "google_secret_manager_secret" "application" {
  for_each  = local.secrets
  secret_id = "ekko-${each.key}"
  replication {
    user_managed {
      replicas { location = var.region }
    }
  }
  depends_on = [google_project_service.api]
}

# Versions and database user passwords are created outside Terraform so that
# their plaintext never enters Terraform configuration, plans, or state.
locals {
  secret_readers = {
    web      = ["app-key", "db-password", "google-oauth-client-secret"]
    dispatch = ["app-key", "db-password"]
    worker   = ["app-key", "db-password", "anthropic-api-key", "openai-api-key", "gemini-api-key"]
    task     = ["app-key", "db-password"]
    migrate  = ["app-key", "db-admin-password"]
  }
  secret_grants = merge([for identity, secrets in local.secret_readers : {
    for secret in secrets : "${identity}/${secret}" => { identity = identity, secret = secret }
  }]...)
}

resource "google_secret_manager_secret_iam_member" "read" {
  for_each  = local.secret_grants
  secret_id = google_secret_manager_secret.application[each.value.secret].id
  role      = "roles/secretmanager.secretAccessor"
  member    = "serviceAccount:${google_service_account.component[each.value.identity].email}"
}

resource "google_project_iam_member" "cloudsql_client" {
  for_each = toset(["web", "dispatch", "worker", "task", "migrate"])
  project  = var.project_id
  role     = "roles/cloudsql.client"
  member   = "serviceAccount:${google_service_account.component[each.key].email}"
}

resource "google_storage_bucket_iam_member" "raw_worker" {
  bucket = google_storage_bucket.raw.name
  role   = "roles/storage.objectUser"
  member = "serviceAccount:${google_service_account.component["worker"].email}"
}

resource "google_storage_bucket_iam_member" "raw_web" {
  bucket = google_storage_bucket.raw.name
  role   = "roles/storage.objectViewer"
  member = "serviceAccount:${google_service_account.component["web"].email}"
}

# ekko:purge-closed-tenants deletes a deleted tenant's raw answers (T39-F6): list and delete.
resource "google_storage_bucket_iam_member" "raw_task" {
  bucket = google_storage_bucket.raw.name
  role   = "roles/storage.objectUser"
  member = "serviceAccount:${google_service_account.component["task"].email}"
}

resource "google_storage_bucket_iam_member" "reports_worker" {
  bucket = google_storage_bucket.reports.name
  role   = "roles/storage.objectUser"
  member = "serviceAccount:${google_service_account.component["worker"].email}"
}

resource "google_storage_bucket_iam_member" "reports_task" {
  bucket = google_storage_bucket.reports.name
  role   = "roles/storage.objectCreator"
  member = "serviceAccount:${google_service_account.component["task"].email}"
}

resource "google_storage_bucket_iam_member" "reports_web" {
  bucket = google_storage_bucket.reports.name
  role   = "roles/storage.objectViewer"
  member = "serviceAccount:${google_service_account.component["web"].email}"
}
