# Operations runbook

## Roll back web traffic

This procedure applies only after the deployment pipeline is completed and
approved for production. Identify a previously verified `ekko-web` revision
that is compatible with the current database schema. With the intended project
and region explicitly selected, run:

```sh
PROJECT_ID=ekko-514612 REGION=europe-west1 scripts/rollback-traffic.sh ekko-web-REVISION
```

Confirm that Cloud Run reports 100% traffic on the requested revision and check
application health. This moves web traffic only. It does not reverse migrations
or restore job images; assess and restore those separately if needed. Migrations
must remain backward compatible so that an earlier revision can run against the
current schema. Exercise this procedure in an isolated environment before use.
