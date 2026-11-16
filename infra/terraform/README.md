# Ekko production infrastructure

This directory targets the existing `ekko-514612` project in `europe-west1`.
It does not manage the GCP project, billing, secret versions, database users,
the Terraform state bucket, application builds, or alert policies. Workload
Identity Federation is defined in `deploy.tf`; the deployment workflow remains
an inactive T10 draft. Alert policies are T36. No Terraform resource should
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
   The web identity can read the admin password solely for logged platform-admin
   operations (T05); compromise of the web identity therefore also exposes that
   credential. The exact BYPASSRLS setup on Cloud SQL must be verified before
   release.
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
5. Copy `production.tfvars.example` to ignored `production.auto.tfvars` and keep
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
