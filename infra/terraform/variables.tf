variable "project_id" {
  description = "Existing production project."
  type        = string
  default     = "ekko-514612"
}

variable "region" {
  description = "Region for all regional resources."
  type        = string
  default     = "europe-west1"

  validation {
    condition     = var.region == "europe-west1"
    error_message = "Ekko data must be stored in europe-west1."
  }
}

variable "raw_bucket_name" {
  description = "Globally unique bucket name for raw responses."
  type        = string
  default     = "ekko-raw"
}

variable "reports_bucket_name" {
  description = "Globally unique bucket name for generated reports."
  type        = string
  default     = "ekko-reports"
}

variable "enable_workloads" {
  description = "Create Cloud Run and Scheduler resources after the image and secret versions are provisioned."
  type        = bool
  default     = false
}

variable "image" {
  description = "Existing web image pinned by Artifact Registry digest; required when workloads are enabled."
  type        = string
  default     = ""
}

variable "job_image" {
  description = "Existing jobs image with Chromium, pinned by Artifact Registry digest; required when workloads are enabled."
  type        = string
  default     = ""
}

variable "enable_schedules" {
  description = "Unpause schedules only when application commands and OIDC verification are deployed."
  type        = bool
  default     = false
}

variable "enable_dashboard_schedules" {
  description = "Enable extraction polling, aggregation and usage rollup independently of daily collection and other schedules. Polling also resumes already queued collection work."
  type        = bool
  default     = false
}

variable "app_db_user" {
  description = "Database role without BYPASSRLS, created outside Terraform."
  type        = string
  default     = "ekko_app"
}

variable "admin_db_user" {
  description = "Migration database role with BYPASSRLS, created outside Terraform."
  type        = string
  default     = "ekko_migrate"
}

variable "app_url" {
  description = "Canonical HTTPS URL for the service, also used for OAuth redirects."
  type        = string
  default     = "https://ekko.maksimer.com"
}

variable "enable_public_access" {
  description = "Allow unauthenticated access after the application and internal endpoint security have been verified."
  type        = bool
  default     = false
}

variable "clickhouse_endpoints" {
  description = "Approved HTTPS endpoints for tenant ClickHouse log integrations."
  type        = list(string)
  default     = []
}

variable "enable_https" {
  description = "Provision the production IP and DNS-authorized certificate; attach the load balancer when workloads exist."
  type        = bool
  default     = false
}

variable "google_oauth_client_id" {
  description = "Google Web OAuth client ID (not a secret). Create the client outside Terraform."
  type        = string
  default     = ""
}

variable "gemini_grounding_approved" {
  description = "Enable Gemini collection only after Google's terms permit Ekko's analysis and retention."
  type        = bool
  default     = false
}

variable "require_two_factor" {
  description = "Require two-factor authentication for the platform admin and partner advisors (docs/spec.md 5.4). Only false while the owner tests before launch."
  type        = bool
  default     = true
}

variable "enable_monitoring" {
  description = "Create operational alert policies and a paused health schedule."
  type        = bool
  default     = false
}
variable "enable_monitoring_schedule" {
  description = "Start health checks only after the monitor command is deployed; independent of collection schedules."
  type        = bool
  default     = false
}
variable "monitoring_email" {
  description = "Platform administrator's approved notification address."
  type        = string
  default     = ""
  validation {
    condition     = var.monitoring_email == "" || can(regex("^[^@ ]+@[^@ ]+\\.[^@ ]+$", var.monitoring_email))
    error_message = "Provide a valid monitoring email address."
  }
}

variable "allow_work_rollout_downgrade" {
  description = "Only for the documented rollback of the work rollout stage (work.tf, docs/t40-f4-produksjonsaksept.md), or the first apply that creates ekko-wake: lets one reviewed plan move the stage backwards. Pass on the command line, never in a tfvars file."
  type        = bool
  default     = false
}

variable "artifact_cleanup_dry_run" {
  description = "Only log what the Artifact Registry cleanup policies would delete. Set false after the dry-run log is reviewed."
  type        = bool
  default     = true
}

variable "artifact_keep_count" {
  description = "Newest versions kept per image (web, jobs). Bounds how far back web rollback can go."
  type        = number
  default     = 20
  validation {
    condition     = var.artifact_keep_count >= 10
    error_message = "Keep at least 10 versions so the running digest and rollback targets survive."
  }
}

variable "artifact_delete_after_days" {
  description = "Image versions older than this, and outside the kept window, are deleted."
  type        = number
  default     = 3
  validation {
    condition     = var.artifact_delete_after_days >= 1
    error_message = "Must be at least 1 day."
  }
}
