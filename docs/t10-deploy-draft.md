# T10 deployment draft: not ready to merge

The deploy job is an inactive fragment under `docs/examples/`; GitHub Actions
will not run it. Do not activate it, apply the Terraform, or treat this branch
as production-ready. `scripts/smoke.sh` always fails deliberately, even if its
placeholder HTTP checks return 200. This fail-closed guard must be replaced by
real authenticated checks, not removed as a shortcut.

The login page and placeholder dashboard exist, but T26 must provide the real
dashboard; T33 and T34 must provide the API and MCP interfaces. The smoke
contract must log in and verify the dashboard and representative API and MCP
responses on the tagged revision. Merely accepting a redirect, a 404, or an
unrelated HTTP 200 is not sufficient. Select an appropriately restricted test
identity and a safe credential provisioning mechanism before enabling deploy.
The specified browser tests for login, onboarding, and dashboard also remain
to be implemented as those flows become available.

Before enabling the workflow, validate both Docker targets and their runtime
commands in a container environment. Review and apply Terraform separately,
verify the WIF identity and all scoped permissions in an isolated GCP project,
and set `GCP_WORKLOAD_IDENTITY_PROVIDER` from its output. The WIF condition
requires the ordinary `.github/workflows/ci.yml` workflow on `main` via
`workflow_ref`. If deployment is moved to a reusable workflow, revisit the
condition and test the `job_workflow_ref` claim before enabling it. See the
[GitHub OIDC claim reference](https://docs.github.com/en/actions/reference/security/oidc)
and [reusable workflow guidance](https://docs.github.com/en/actions/how-tos/secure-your-work/security-harden-deployments/oidc-with-reusable-workflows). Bootstrap secret
versions, database users, initial images, public access and workloads according
to `infra/terraform/README.md`. Pin traffic to tested revisions before any
infrastructure update; the preflight rejects LATEST, missing and partial traffic
allocations. Validate Cloud SQL backup completion, the candidate tag URL and
migration execution in that isolated environment. Production migrations must
remain backward compatible: a smoke failure retains old web traffic but occurs
after migration.

`bash tests/deploy/test-deploy.sh` uses mocked commands to check unpinned
traffic and backup failures stop before mutation, an intentionally failing
smoke test never invokes traffic promotion or worker update, the success path
orders backup, migration, promotion and jobs, and rollback targets an explicit
revision. It does not prove Cloud Run behavior. A deliberately failing real
revision in an isolated non-production service, followed by traffic inspection,
is still required. There has been no live deployment, real GCP plan, image build
or production traffic test in this branch. Worker updates can fail after web
promotion; recovery for this partial state must be exercised before activation.

See `docs/runbook.md` for the reviewed traffic rollback procedure.
