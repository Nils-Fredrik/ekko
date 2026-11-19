locals {
  monitoring_count    = var.enable_monitoring && var.enable_workloads ? 1 : 0
  monitoring_channels = [for channel in google_monitoring_notification_channel.operations : channel.name]
  monitoring_runbook  = "https://github.com/Nils-Fredrik/ekko/blob/main/docs/runbook.md"
  database_signals    = var.enable_monitoring && var.enable_workloads ? toset(["cpu", "disk"]) : toset([])
  application_signals = var.enable_monitoring && var.enable_workloads ? toset(["engine_error_rate", "collection_overdue", "extraction_overdue", "work_stopped"]) : toset([])
}

resource "google_monitoring_notification_channel" "operations" {
  count        = local.monitoring_count
  display_name = "Ekko platform operations"
  type         = "email"
  labels       = { email_address = var.monitoring_email }
  lifecycle {
    precondition {
      condition     = var.monitoring_email != ""
      error_message = "Monitoring requires an approved notification address."
    }
  }
  depends_on = [google_project_service.api]
}

resource "google_monitoring_alert_policy" "web_errors" {
  count                 = local.monitoring_count
  display_name          = "Ekko web: over 2% HTTP 5xx in 10 minutes"
  combiner              = "OR"
  notification_channels = local.monitoring_channels
  documentation {
    content   = "Inspect the serving revision and request logs, then follow the rollback runbook. ${local.monitoring_runbook}"
    mime_type = "text/markdown"
  }
  conditions {
    display_name = "HTTP 5xx / all responses > 0.02"
    condition_threshold {
      filter             = "resource.type = \"cloud_run_revision\" AND resource.labels.service_name = \"ekko-web\" AND metric.type = \"run.googleapis.com/request_count\" AND metric.labels.response_code_class = \"5xx\""
      denominator_filter = "resource.type = \"cloud_run_revision\" AND resource.labels.service_name = \"ekko-web\" AND metric.type = \"run.googleapis.com/request_count\""
      comparison         = "COMPARISON_GT"
      threshold_value    = 0.02
      duration           = "0s"
      aggregations {
        alignment_period     = "600s"
        per_series_aligner   = "ALIGN_SUM"
        cross_series_reducer = "REDUCE_SUM"
        group_by_fields      = ["resource.label.service_name"]
      }
      denominator_aggregations {
        alignment_period     = "600s"
        per_series_aligner   = "ALIGN_SUM"
        cross_series_reducer = "REDUCE_SUM"
        group_by_fields      = ["resource.label.service_name"]
      }
      trigger { count = 1 }
    }
  }
  alert_strategy { auto_close = "1800s" }
}

resource "google_monitoring_alert_policy" "job_failure" {
  count                 = local.monitoring_count
  display_name          = "Ekko Cloud Run job execution failed"
  combiner              = "OR"
  notification_channels = local.monitoring_channels
  documentation {
    content   = "Inspect the failed execution before retrying; a deploy may already have changed database or traffic. ${local.monitoring_runbook}"
    mime_type = "text/markdown"
  }
  conditions {
    display_name = "At least one failed execution"
    condition_threshold {
      filter          = "resource.type = \"cloud_run_job\" AND resource.labels.job_name = starts_with(\"ekko-\") AND metric.type = \"run.googleapis.com/job/completed_execution_count\" AND metric.labels.result = \"failed\""
      comparison      = "COMPARISON_GT"
      threshold_value = 0
      duration        = "0s"
      aggregations {
        alignment_period   = "60s"
        per_series_aligner = "ALIGN_SUM"
      }
      trigger { count = 1 }
    }
  }
  alert_strategy { auto_close = "1800s" }
}

resource "google_monitoring_alert_policy" "database" {
  for_each              = local.database_signals
  display_name          = "Ekko SQL ${each.key}: over 80% for 30 minutes"
  combiner              = "OR"
  notification_channels = local.monitoring_channels
  documentation {
    content   = "Inspect Cloud SQL capacity and active work. Do not delete data or restart without investigating. ${local.monitoring_runbook}"
    mime_type = "text/markdown"
  }
  conditions {
    display_name = "${each.key} utilization > 0.8"
    condition_threshold {
      filter          = "resource.type = \"cloudsql_database\" AND resource.labels.database_id = \"${var.project_id}:${google_sql_database_instance.postgres.name}\" AND metric.type = \"cloudsql.googleapis.com/database/${each.key}/utilization\""
      comparison      = "COMPARISON_GT"
      threshold_value = 0.8
      duration        = "1800s"
      aggregations {
        alignment_period   = "60s"
        per_series_aligner = "ALIGN_MEAN"
      }
      trigger { count = 1 }
    }
  }
  alert_strategy { auto_close = "3600s" }
}

resource "google_monitoring_alert_policy" "application" {
  for_each              = local.application_signals
  display_name          = "Ekko application: ${each.key}"
  combiner              = "OR"
  notification_channels = local.monitoring_channels
  documentation {
    content   = "Health check detected ${each.key}. Logs contain only counts and internal identifiers. engine_error_rate is >20% over the last 24 hours; collection_overdue means pending runs after 6 hours; extraction_overdue means submitted extraction after 24 hours; work_stopped means durable work that failed for 24 hours and was stopped (ADR 0032, re-arm with ekko:work:retry). ${local.monitoring_runbook}"
    mime_type = "text/markdown"
  }
  conditions {
    display_name = "Health check reported ${each.key}"
    condition_matched_log {
      filter = "resource.type=\"cloud_run_job\" resource.labels.job_name=\"ekko-task\" jsonPayload.message=\"monitoring.issue\" jsonPayload.context.signal=\"${each.key}\""
    }
  }
  alert_strategy {
    notification_rate_limit { period = "3600s" }
    auto_close = "3600s"
  }
}

resource "google_logging_metric" "monitoring_heartbeat" {
  count  = local.monitoring_count
  name   = "ekko/monitoring_completed"
  filter = "resource.type=\"cloud_run_job\" resource.labels.job_name=\"ekko-task\" jsonPayload.message=\"monitoring.completed\""
  metric_descriptor {
    metric_kind = "DELTA"
    value_type  = "INT64"
    unit        = "1"
  }
}

resource "google_monitoring_alert_policy" "monitoring_absent" {
  count                 = local.monitoring_count
  display_name          = "Ekko health check missing for 45 minutes"
  enabled               = var.enable_monitoring_schedule
  combiner              = "OR"
  notification_channels = local.monitoring_channels
  documentation {
    content   = "Check ekko-monitor-health Scheduler and ekko-task executions. Absence detection starts after the first heartbeat; verify one on activation. ${local.monitoring_runbook}"
    mime_type = "text/markdown"
  }
  conditions {
    display_name = "No completed health check"
    condition_absent {
      filter   = "resource.type = \"cloud_run_job\" AND resource.labels.job_name = \"ekko-task\" AND metric.type = \"logging.googleapis.com/user/${google_logging_metric.monitoring_heartbeat[0].name}\""
      duration = "2700s"
      aggregations {
        alignment_period     = "60s"
        per_series_aligner   = "ALIGN_SUM"
        cross_series_reducer = "REDUCE_SUM"
      }
      trigger { count = 1 }
    }
  }
}
