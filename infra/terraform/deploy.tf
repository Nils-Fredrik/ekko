# GitHub can impersonate CI only from the protected main branch of this repository.
resource "google_iam_workload_identity_pool" "github" {
  workload_identity_pool_id = "ekko-github"
  display_name              = "Ekko GitHub Actions"
  depends_on                = [google_project_service.api]
}

resource "google_iam_workload_identity_pool_provider" "github" {
  workload_identity_pool_id          = google_iam_workload_identity_pool.github.workload_identity_pool_id
  workload_identity_pool_provider_id = "github"
  attribute_mapping = {
    "google.subject"          = "assertion.sub"
    "attribute.repository_id" = "assertion.repository_id"
  }
  attribute_condition = "assertion.repository_id == '1456601992' && assertion.repository_owner_id == '1542112' && assertion.ref == 'refs/heads/main' && assertion.event_name == 'push'"
  oidc { issuer_uri = "https://token.actions.githubusercontent.com" }
}

resource "google_service_account_iam_member" "github_ci" {
  service_account_id = google_service_account.component["ci"].name
  role               = "roles/iam.workloadIdentityUser"
  member             = "principalSet://iam.googleapis.com/${google_iam_workload_identity_pool.github.name}/attribute.repository_id/1456601992"
}

# Read-only project access for discovery; mutations are limited to these workloads.
resource "google_project_iam_member" "ci_run_viewer" {
  project = var.project_id
  role    = "roles/run.viewer"
  member  = "serviceAccount:${google_service_account.component["ci"].email}"
}

resource "google_cloud_run_v2_service_iam_member" "ci_deploy_web" {
  count    = var.enable_workloads ? 1 : 0
  location = var.region
  name     = google_cloud_run_v2_service.web[0].name
  role     = "roles/run.developer"
  member   = "serviceAccount:${google_service_account.component["ci"].email}"
}

resource "google_cloud_run_v2_job_iam_member" "ci_deploy_jobs" {
  for_each = local.jobs
  project  = var.project_id
  location = var.region
  name     = google_cloud_run_v2_job.job[each.key].name
  role     = "roles/run.developer"
  member   = "serviceAccount:${google_service_account.component["ci"].email}"
}

resource "google_service_account_iam_member" "ci_act_as" {
  for_each           = toset(["web", "dispatch", "worker", "task", "migrate"])
  service_account_id = google_service_account.component[each.key].name
  role               = "roles/iam.serviceAccountUser"
  member             = "serviceAccount:${google_service_account.component["ci"].email}"
}

resource "google_project_iam_custom_role" "ci_backup" {
  role_id     = "ekkoCiBackup"
  title       = "Ekko CI Cloud SQL backup"
  permissions = ["cloudsql.backupRuns.create", "cloudsql.backupRuns.get", "cloudsql.instances.get", "cloudsql.operations.get"]
}

resource "google_project_iam_member" "ci_backup" {
  project = var.project_id
  role    = google_project_iam_custom_role.ci_backup.name
  member  = "serviceAccount:${google_service_account.component["ci"].email}"
}

output "github_workload_identity_provider" {
  value = google_iam_workload_identity_pool_provider.github.name
}
