locals {
  scheduled_jobs = var.enable_workloads ? {
    dispatch        = { schedule = "0 1 * * *", job = "dispatch", args = [] }
    usage_rollup    = { schedule = "0 * * * *", job = "task", args = ["php artisan ekko:usage-rollup"] }
    aggregate       = { schedule = "0 * * * *", job = "task", args = ["php artisan ekko:aggregate --sync"] }
    log_sync        = { schedule = "0 4 * * *", job = "task", args = ["php artisan ekko:sync-bot-hits && php artisan ekko:sync-ga4"] }
    weekly_reports  = { schedule = "30 6 * * 1", job = "task", args = ["php artisan ekko:send-reports"] }
    monthly_reports = { schedule = "30 6 1 * *", job = "task", args = ["php artisan ekko:send-reports"] }
  } : {}
  ticks = var.enable_workloads ? {
    extraction-poll = "*/15 * * * *"
  } : {}
}

moved {
  from = google_cloud_scheduler_job.tick["usage-rollup"]
  to   = google_cloud_scheduler_job.job["usage_rollup"]
}

resource "google_cloud_scheduler_job" "job" {
  for_each  = local.scheduled_jobs
  name      = "ekko-${replace(each.key, "_", "-")}"
  region    = var.region
  schedule  = each.value.schedule
  time_zone = "Europe/Oslo"
  paused    = !var.enable_schedules
  http_target {
    http_method = "POST"
    uri         = "https://run.googleapis.com/v2/projects/${var.project_id}/locations/${var.region}/jobs/${google_cloud_run_v2_job.job[each.value.job].name}:run"
    headers     = { "Content-Type" = "application/json" }
    body = each.value.job == "dispatch" ? base64encode("{}") : base64encode(jsonencode({
      overrides = { containerOverrides = [{ args = each.value.args }] }
    }))
    oauth_token { service_account_email = google_service_account.component["scheduler"].email }
  }
  depends_on = [google_cloud_run_v2_job_iam_member.scheduler_dispatch, google_cloud_run_v2_job_iam_member.scheduler_task]
}

resource "google_cloud_scheduler_job" "tick" {
  for_each  = local.ticks
  name      = "ekko-${each.key}"
  region    = var.region
  schedule  = each.value
  time_zone = "Europe/Oslo"
  paused    = !var.enable_schedules
  http_target {
    http_method = "POST"
    uri         = "${google_cloud_run_v2_service.web[0].uri}/internal/tick/${each.key}"
    oidc_token {
      service_account_email = google_service_account.component["scheduler"].email
      audience              = google_cloud_run_v2_service.web[0].uri
    }
  }
}
