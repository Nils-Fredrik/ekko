# Ekko production infrastructure

This directory targets the existing `ekko-514612` project in `europe-west1`.
It does not manage the GCP project, billing, secret versions, database users,
the Terraform state bucket, application builds, deployment workflows or alert
policies. Workload Identity Federation and deployment are T10; alert policies
are T36. No Terraform resource should ever contain a secret value.

## Bootstrap and prerequisites

1. An operator provisions a separate private, versioned GCS state bucket with
   access restricted to infrastructure administrators. Initialize with
   `terraform init -backend-config="bucket=YOUR_STATE_BUCKET"`. Do not commit
   backend credentials, state or plan files. Never reuse the application buckets.
2. Review `terraform plan` before any apply. With the defaults, only foundation
   resources are planned; Cloud Run and schedules are deliberately disabled.
   Provisioning the foundation creates billable resources, including Cloud SQL.
3. After an approved foundation apply, an operator creates database logins and
   credentials outside Terraform. `ekko_app` must not have BYPASSRLS or broad
   Cloud SQL administrative privileges. `ekko_migrate` is the distinct
   privileged migration role required by the RLS design. Verify role attributes
   and grants against the isolation requirements before using the system.
   Only the migrate identity can read the privileged database password. The web
   service receives only the regular database login and has no Secret Manager
   grant for the migration password or DB_ADMIN_* environment variables.
   Platform administration uses the normal RLS connection today. If a future
   operation needs BYPASSRLS, design a separately audited execution path with a
   dedicated role without table ownership or DDL rights; never reuse the migration
   credentials in web. The exact migration-role setup on Cloud SQL must still be
   verified before release.
4. Add secret versions directly in Secret Manager, using a secure process that
   does not expose values in shell history, logs, source control or Terraform
   state. The runtime identity has access only to its listed secrets. Push the
   application image to the Artifact Registry repository. For the initial
   rollout use a temporary HTTPS value for `app_url`, then set it to the actual
   Cloud Run URL in a second reviewed deployment. Configure OAuth redirects
   and verify the URL before accepting user traffic.
5. Set `enable_workloads = true`, `image` to the immutable image digest and
   `app_url` to a temporary HTTPS URL for bootstrap; review a new plan before
   applying. After creation set `app_url` to the actual service URL and review
   the second plan before accepting traffic.
   Create all required secret versions first: Cloud Run validates secret access
   and versions at deployment. The image must implement the web process and
   job commands described in the specification. Subsequent image changes need
   coordinated Terraform and deployment ownership (T10). After initial creation
   Terraform ignores image changes, web traffic, web revision names and deploy
   client metadata, which are managed by T10.
   T10 must pin production traffic to a tested revision before infrastructure
   updates that create revisions; otherwise a `LATEST` traffic target can expose
   an untested revision. Never apply infrastructure changes without reviewing
   their impact on traffic. The service starts private; set
   `enable_public_access = true` only after the application and internal-route
   OIDC checks are verified and the canonical URL is configured.
6. Schedules are paused by default. Enable them only after the commands and
   internal endpoints are implemented and tested. Since the web service is
   public, application middleware **must validate** the Scheduler OIDC token,
   including issuer, audience and the exact Scheduler service account, on
   `/internal/tick/*` endpoints. Public Cloud Run IAM does not protect a path.
   The future dispatcher must bound the worker task-count override at eight;
   the worker uses Cloud Run automatic parallelism for the requested count.

The `ekko-task` identity can create PDF objects in the reports bucket. Scheduled
report generation uploads each new PDF under a unique object name. The worker
allows 420 seconds per queue job to cover up to three 120-second engine calls
and retry delays. All workloads use a 450-second database queue reservation.
Keep individual job timeout overrides below the reservation time. Cloud Run
allows 3900 seconds per worker task so a job can finish after the worker reaches
its 3300-second process lifetime.

The default bucket names are global. If they are already owned elsewhere,
choose unique names using `raw_bucket_name` and `reports_bucket_name`; do not
import or delete an existing bucket without verifying ownership. Standard raw
response versions transition to Nearline at 90 days. Both live and noncurrent
versions qualify for deletion 400 days after their creation. Cloud Storage
first archives live versions and then permanently deletes them under the
noncurrent rule. Lifecycle processing is asynchronous. Soft delete is disabled
for the raw bucket so it adds no retention period.

Secret payloads use a user-managed replica in `europe-west1`. Google Cloud fixes
the replica location at secret creation.

`terraform fmt -check -recursive`, `terraform validate`, and a reviewed live
`terraform plan` are required checks. After an approved apply, run plan again to
verify that it has no changes. Without GCP access an actual plan and the clean
post-apply acceptance criterion cannot be established.
