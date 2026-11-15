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
  default     = ""
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
