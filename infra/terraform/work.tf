# Targeted wake-ups of durable work through Cloud Tasks (T40-F4, ADR 0033, docs/spec.md 7.1).
#
# The rollout stage is committed configuration, not a tfvars value: the production choice lives in reviewed code,
# so an apply from any up-to-date worktree gives the same schedules and flags. It only moves through a reviewed PR
# (docs/t40-f4-produksjonsaksept.md):
#
#   legacy  Today's production. No Cloud Tasks resources; the queue publisher and chaining stay off; extraction-poll,
#           aggregate and usage-rollup follow enable_schedules / enable_dashboard_schedules as before.
#   ready   Cloud Tasks API, queue, wake-up identity and IAM exist and the hourly recovery check runs; the application
#           still uses the queue publisher with chaining off. extraction-poll, aggregate and usage-rollup run, whatever
#           a local tfvars says, because they are production's processing path (enabled by the owner 18.11.2026).
#   shadow  EKKO_WORK_CHAINING=true and EKKO_WORK_PUBLISHER=cloud_tasks: work is woken by Cloud Tasks. The three old
#           schedules keep running as the fallback while the comparison is measured.
#   event   As shadow, but the three old schedules are paused. Other schedules are never changed by the stage.
#
# The guard below stops a plan that would move the stage backwards, such as an apply from a worktree whose branch
# predates the last stage change: the applied stage is read from a label on ekko-wake. Rolling back on purpose
# needs -var=allow_work_rollout_downgrade=true for that one reviewed plan (it also skips reading the label, which
# the first apply of a new environment needs, before ekko-wake exists).
locals {
  work_rollout_stage = "legacy"

  work_rollout_ranks = { legacy = 0, ready = 1, shadow = 2, event = 3 }
  work_rollout_rank  = local.work_rollout_ranks[local.work_rollout_stage]
  work_tasks_enabled = var.enable_workloads && local.work_rollout_rank >= 1
  work_wakeup_active = local.work_tasks_enabled && local.work_rollout_rank >= 2
  work_rollout_applied = try(
    data.google_cloud_run_v2_job.work_rollout_marker[0].effective_labels["ekko-work-rollout"],
    "legacy",
  )

  # extraction-poll, aggregate and usage-rollup: the old processing path (ADR 0030/0032).
  legacy_processing_paused = local.work_rollout_rank == 0 ? !(var.enable_schedules || var.enable_dashboard_schedules) : local.work_rollout_rank >= 3

  work_tasks_audience = "${var.app_url}/internal/work"
  # Only set from ready on, so the legacy stage plans no change to any revision.
  work_env = local.work_tasks_enabled ? {
    EKKO_WORK_CHAINING              = tostring(local.work_wakeup_active)
    EKKO_WORK_PUBLISHER             = local.work_wakeup_active ? "cloud_tasks" : "queue"
    EKKO_WORK_TASKS_QUEUE           = google_cloud_tasks_queue.work[0].name
    EKKO_WORK_TASKS_SERVICE_ACCOUNT = google_service_account.work_tasks[0].email
    EKKO_WORK_TASKS_AUDIENCE        = local.work_tasks_audience
  } : {}

  # Identities that register work and so publish wake-ups: web (wizard, recovery check), worker (collection,
  # extraction, the processor itself) and task (reports, ekko:work:retry). Dispatch registers none.
  work_publishers = local.work_tasks_enabled ? toset(["web", "worker", "task"]) : toset([])
}

data "google_cloud_run_v2_job" "work_rollout_marker" {
  count    = var.enable_workloads && !var.allow_work_rollout_downgrade ? 1 : 0
  name     = "ekko-wake"
  location = var.region
}

resource "terraform_data" "work_rollout_guard" {
  input = local.work_rollout_stage
  lifecycle {
    precondition {
      condition     = var.allow_work_rollout_downgrade || local.work_rollout_rank >= lookup(local.work_rollout_ranks, local.work_rollout_applied, 0)
      error_message = "Production runs work rollout stage \"${local.work_rollout_applied}\", but this configuration has \"${local.work_rollout_stage}\". Plan from up-to-date origin/main; roll back only by the documented procedure with -var=allow_work_rollout_downgrade=true."
    }
  }
}

data "google_project" "current" {
  count = local.work_tasks_enabled ? 1 : 0
}

# The OIDC identity of the wake-up tasks. It has no roles of its own: it only proves to web that a task came from
# Ekko's queue (AuthenticateWorkTasks checks this account and the audience).
resource "google_service_account" "work_tasks" {
  count        = local.work_tasks_enabled ? 1 : 0
  account_id   = "ekko-tasks"
  display_name = "Ekko work wake-ups"
  depends_on   = [google_project_service.api]
}

resource "google_cloud_tasks_queue" "work" {
  count    = local.work_tasks_enabled ? 1 : 0
  name     = "ekko-work"
  location = var.region

  # Wake-ups are small and coalesced; a low ceiling also bounds web requests if something loops.
  rate_limits {
    max_dispatches_per_second = 5
    max_concurrent_dispatches = 5
  }

  # 425 from web keeps a delivery open until a worker has taken the work. After an hour the task is dropped and the
  # hourly recovery check publishes the signal again if it is still pending.
  retry_config {
    max_attempts       = -1
    max_retry_duration = "3600s"
    min_backoff        = "10s"
    max_backoff        = "300s"
    max_doublings      = 4
  }

  # Task logs carry the task name (internal ids) and outcome only; full sampling for the production acceptance.
  stackdriver_logging_config {
    sampling_ratio = 1.0
  }

  depends_on = [google_project_service.api]
}

resource "google_cloud_tasks_queue_iam_member" "enqueuer" {
  for_each = local.work_publishers
  project  = var.project_id
  location = var.region
  name     = google_cloud_tasks_queue.work[0].name
  role     = "roles/cloudtasks.enqueuer"
  member   = "serviceAccount:${google_service_account.component[each.key].email}"
}

# Creating a task with an OIDC token of ekko-tasks requires actAs on that one account, not project-wide.
resource "google_service_account_iam_member" "work_tasks_act_as" {
  for_each           = local.work_publishers
  service_account_id = google_service_account.work_tasks[0].name
  role               = "roles/iam.serviceAccountUser"
  member             = "serviceAccount:${google_service_account.component[each.key].email}"
}

# The Cloud Tasks service agent mints the OIDC token for ekko-tasks at delivery.
resource "google_service_account_iam_member" "work_tasks_agent" {
  count              = local.work_tasks_enabled ? 1 : 0
  service_account_id = google_service_account.work_tasks[0].name
  role               = "roles/iam.serviceAccountUser"
  member             = "serviceAccount:service-${data.google_project.current[0].number}@gcp-sa-cloudtasks.iam.gserviceaccount.com"
  depends_on         = [google_project_service.api]
}

# Needed only while public access is off; the application checks the token on every /internal/work route anyway.
resource "google_cloud_run_v2_service_iam_member" "work_tasks" {
  count    = local.work_tasks_enabled ? 1 : 0
  location = var.region
  name     = google_cloud_run_v2_service.web[0].name
  role     = "roles/run.invoker"
  member   = "serviceAccount:${google_service_account.work_tasks[0].email}"
}
