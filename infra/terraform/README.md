# Ekko production infrastructure

This directory targets the existing `ekko-514612` project in `europe-west1`.
It does not manage the GCP project, billing, secret versions, database users,
the Terraform state bucket, or application builds. Alert policies and their email channel are managed in `monitoring.tf`. Workload
Identity Federation is defined in `deploy.tf`. The deployment workflow runs on
push to `main` when `EKKO_DEPLOY_ENABLED=true`; this repository variable is now
enabled after the documented public acceptance checks. The first automatic
main deployment is verified after the owner's merge of T10. Collection schedules
remain paused independently. `enable_dashboard_schedules=true` enables only extraction polling, hourly aggregation and hourly usage rollup. It does not schedule new daily collection, reports, alerts, integration sync or tenant deletion. Polling can resume collection work already queued and submits billable extraction batches, so it still requires the owner's authorization. The global `enable_schedules` flag enables all collection schedules, including these three. T36 monitoring is enabled separately from collection schedules; see `docs/runbook.md`. No Terraform resource should
ever contain a secret value.

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
   In this configuration only the migrate identity can read the privileged database password. The web
   service is configured to receive only the regular database login and has no Secret Manager
   grant for the migration password or DB_ADMIN_* environment variables.
   Platform administration uses the normal RLS connection today. If a future
   operation needs BYPASSRLS, design a separately audited execution path with a
   dedicated role without table ownership or DDL rights; never reuse the migration
   credentials in web. The exact migration-role setup on Cloud SQL must still be
   verified before release.
   Repeat these checks after recovery or role rotation: both logins work;
   `ekko_app` has no superuser, CREATEDB, CREATEROLE, replication, BYPASSRLS or
   role memberships; `ekko_migrate` has BYPASSRLS, schema CREATE/USAGE and
   SET ROLE access to `ekko_app`, without superuser, CREATEDB or CREATEROLE.
   With a synthetic FORCE ROW LEVEL SECURITY table, the app role must see no
   rows without a tenant and only the selected tenant's rows; the migration
   role must see all rows. Roll back the probe and remove temporary operators.
4. Add secret versions directly in Secret Manager, never through Terraform or
   command-line arguments. Required at launch: app key, both database passwords,
   Google OAuth client secret, Anthropic, OpenAI and Gemini keys. Microsoft,
   Perplexity and DataForSEO are not mounted or granted to runtime identities.
   Their existing empty secret containers are retained.
5. For the first bootstrap, copy `production.tfvars.example` to ignored
   `production.auto.tfvars`. After bootstrap the authoritative copy lives in the
   state bucket (`gs://ekko-514612-tfstate/ekko/production/production.auto.tfvars`,
   private and versioned): run `scripts/terraform-vars.sh pull` before every
   plan and `scripts/terraform-vars.sh push` after an approved apply that
   changed a value. Push is refused if someone else pushed since your pull, and
   if a variable name looks like a secret (a heuristic: it cannot see
   secrets in values, so keep secrets in Secret Manager only). Never plan from an old local copy. Keep
   `enable_https = true` in every production plan after bootstrap. Run plan/apply
   with workloads disabled first to reserve the IP and request DNS authorization.
   `terraform output -json production_dns_records` gives the exact A and CNAME
   records for the existing DNS provider. In Cloudflare, set **both** records to
   **DNS only** (grey cloud), without proxying or CNAME flattening of the
   authorization record. Keep the CNAME for certificate renewal.
   No AAAA record is provisioned; remove conflicting old records when switching.
   Configure the Google Web OAuth client as described below, then set its public
   ID in `google_oauth_client_id`. Set `enable_workloads = true`, `image` and
   `job_image` to the verified immutable CI digests. `app_url` is always
   `https://ekko.maksimer.com`; no temporary URL or OAuth callback is needed.
   Create all required secret versions first: Cloud Run validates secret access
   and versions at deployment. The image must implement the web process and
   job commands described in the specification. Subsequent image changes need
   coordinated Terraform and deployment ownership (T10). After initial creation
   Terraform ignores image changes, web traffic and deploy client metadata,
   which are managed by T10. Do not ignore `template.revision`: reusing a named
   CLI revision when changing configuration fails with Cloud Run HTTP 409.
   Terraform leaves the revision name unset so the API can generate one.
   After a named CLI deployment, a plan may therefore clear that name; review
   it with the other infrastructure changes while traffic stays pinned.
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

Web and `ekko-task` both have object access on the reports bucket. Web stores the
logo there, and the task job reads that logo when it writes the PDF. Web then
downloads the same object. The task job also reads `ekko-anthropic-api-key` as
`ANTHROPIC_API_KEY`, because the monthly insight runs in that job. The worker
allows 420 seconds per queue job to cover up to three 120-second engine calls
and retry delays. All workloads use a 450-second database queue reservation.
Keep individual job timeout overrides below the reservation time. Cloud Run
allows 3900 seconds per worker task so a job can finish after the worker reaches
its 3300-second process lifetime.

Event-driven work wake-ups (T40-F4, ADR 0033) are in `work.tf`. Their rollout
stage is a committed local, not a tfvars value (`legacy` was production before
T40-F4; production runs the stage in `main` after an approved apply): from `ready` on it, not `enable_dashboard_schedules`, decides whether
`extraction-poll`, `aggregate` and `usage-rollup` run, so another worktree's
tfvars cannot change them. The applied stage is a label on `ekko-wake`; a plan that
would move it backwards fails on `terraform_data.work_rollout_guard` unless
`-var=allow_work_rollout_downgrade=true` is passed for a reviewed rollback. Follow
`docs/t40-f4-produksjonsaksept.md` for every stage change.

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

## Domain and Google OAuth

The HTTPS resources are in `https.tf`: global external Application Load Balancer,
serverless NEG, TLS 1.2+ certificate map, HTTP-to-HTTPS redirect, and no CDN.
The IP/certificate resources can exist before Cloud Run. Forwarding rules and
backends are created only with workloads. These are billable resources; the old
infrastructure estimate excludes them. See ADR 0024 for ingress and client-IP
verification requirements. Do not open public IAM before those checks pass.

Create a **Web application** in Google Auth Platform for `ekko-514612`:

- Authorized domain: `maksimer.com`.
- Homepage/origin: `https://ekko.maksimer.com` (JavaScript origins are not needed
  for this server-side Socialite flow).
- Exact redirect: `https://ekko.maksimer.com/auth/google/callback`.
- Only identity scopes: `openid`, `email`, `profile`.
- External audience is needed for invited partner/customer Google accounts;
  testing mode must list the invited test accounts. Internal audience would
  exclude accounts outside the owning Workspace organization.

The owner completes branding/contact and consent settings. Store the client
secret as a new version of `ekko-google-oauth-client-secret` directly in Secret
Manager. Never place it in tfvars, chat, command history or a PR. The client ID
is non-secret and is the only OAuth value Terraform needs. Do not substitute an
IAP OAuth client or IAM workforce OAuth client; this is Google Sign-In for the
application. Google describes the setup in its
[web-server OAuth guide](https://developers.google.com/identity/protocols/oauth2/web-server#creatingcred).

After workloads exist, use the migration identity to run `php artisan db:seed
--force` with the production image: this creates package defaults and direct
engines, without demo customers or fake prices. Review existing package and market
settings (seeding preserves them), enter approved `engine_prices`, and confirm
quotas before enabling schedules. Gemini remains disabled until Google's
Search-grounding terms are resolved; set `gemini_grounding_approved=true` and
explicitly enable the engine only then. This does not require new secrets.

## Artifact Registry cleanup (T10-F5)

The `ekko` repository has cleanup policies managed by Terraform
(`foundation.tf`). CI tags every image with its commit SHA, so the delete rule
uses `tag_state = "ANY"` and deletes versions older than
`artifact_delete_after_days` (default 3 days). KEEP wins over DELETE, and two
rules keep versions:

- `keep-live` keeps every version with a tag starting with `live`.
  `scripts/deploy.sh` moves the `live` tag on `web` and `jobs` to the promoted
  digests after a successful deployment, and `scripts/rollback-traffic.sh` moves
  the `web` tag to the rollback revision's image. The digest serving traffic is
  therefore kept even after a rollback or a run of failed deployments.
- `keep-recent-versions` keeps the newest `artifact_keep_count` (default 20)
  versions of each image. Older rollback targets are kept only while they are
  younger than the retention age. A revision whose image is deleted cannot
  start instances.

The `live` tag protects only what the last successful deployment or rollback
set. If a job is changed manually (for example after a partial deployment, see
the runbook), tag its digest with
`gcloud artifacts docker tags add <image>@<digest> <image>:live`.

CI has repository-scoped writer access plus the `ekkoCiArtifactTags` custom
role with only `artifactregistry.tags.delete`, also bound only on `ekko`.
`gcloud artifacts docker tags add` deletes an existing tag before recreating
it; writer alone cannot move `live`. The additional role does not permit
deleting images or repositories. When repairing an existing installation,
review a targeted plan for `google_project_iam_custom_role.ci_artifact_tags`
and `google_artifact_registry_repository_iam_member.ci_artifact_tags`, then
rerun the failed main deployment. Do not bypass the merge queue's requirement
for a successful main deployment.

Production verification, 20 November 2026 (U15 / Board task:98): after cross-review,
the saved targeted plan created the role and repository binding (2 add, 0 change,
0 destroy). Live IAM confirmed only `artifactregistry.tags.delete` and only the
CI account on `ekko`; the subsequent targeted plan returned no changes.
[Main deployment 44723920806, attempt 2](https://github.com/Nils-Fredrik/ekko/actions/runs/44723920806/attempts/2)
then passed the authenticated smoke test, traffic promotion, job updates and
both `live` tag moves. Cleanup dry-run acceptance remains tracked separately
in T10-F5 / Board task:111.

### Dry run

`artifact_cleanup_dry_run` defaults to `true`, so the policies only log what
they would delete. Terraform also enables Data Write audit logs for
`artifactregistry.googleapis.com` (`google_project_iam_audit_config`), which the
dry-run entries require. Cleanup runs about once a day. In Logs Explorer for
project `ekko-514612`, use:

```
protoPayload.serviceName="artifactregistry.googleapis.com"
protoPayload.methodName="google.devtools.artifactregistry.v1.ArtifactRegistry.BatchDeleteVersions"
protoPayload.request.validateOnly=true
```

Each entry lists the versions that would be deleted
(`protoPayload.request.names`). Before turning dry run off:

1. Make sure the `live` tags exist (`gcloud artifacts docker images list
   europe-west1-docker.pkg.dev/ekko-514612/ekko/web --include-tags`, likewise
   `jobs`). A deployment made after this change sets them. Otherwise, tag the
   current digests manually.
2. Check that no digest from `gcloud run services describe ekko-web`, from
   `gcloud run jobs describe <job>` for every job, or from the intended rollback
   targets is in the log.
3. Set `artifact_cleanup_dry_run = false` in `production.auto.tfvars` in a
   separate, reviewed change and apply it. Deleted versions cannot be restored.
