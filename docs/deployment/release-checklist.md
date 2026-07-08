# Release Checklist

Use this for every deployment. Hold and Staging are low-ceremony; Production is
strict.

## Before you deploy

- [ ] Change is merged to `main` on GitHub (via PR) and CI/local checks pass.
- [ ] `composer validate` is clean and `composer.lock` is committed.
- [ ] `drush config:status` is clean locally (no unexported config).
- [ ] You know which **one** application you are deploying.

## Deploy (per app)

- [ ] SSH to the server as the deploy user.
- [ ] Run the app's script with `--dry-run` first.
- [ ] Read the **deployment summary**: environment, path, web root, branch,
      current + remote commit, database. Confirm they are the intended app.
- [ ] Run the real deploy; complete the confirmation prompt.
- [ ] Note the printed **backup directory** and **log file**.

## Verify after deploy

- [ ] Deployment report shows **Verification: PASS** (WARNING acceptable if the
      warnings are understood; **FAIL** means roll back).
- [ ] A journal entry was written under `shared/deployments/` for this deploy.
- [ ] Re-run standalone if needed: `bash deploy/verify-deployment.sh <app path>`.
- [ ] Site loads; for Hold, `/admin/config/myeventlane/analytics/status` is green.
- [ ] Build number, commit, and versions in the report look right.

## Production only

- [ ] Deploying a reviewed commit/tag on `main`.
- [ ] `MEL_ALLOW_PRODUCTION_DEPLOY=1` set for this run.
- [ ] Second operator aware (change window).
- [ ] Rollback command copied somewhere before starting.

## If anything looks wrong

- [ ] Stop. Run the printed `rollback-<app>.sh <backup_dir>`.
- [ ] Verify the site recovers.
- [ ] Diagnose before re-attempting.

## Never

- [ ] Never run `deploy/push-and-deploy.sh`, `site-deploy.sh`, or
      `cpanel-post-deploy.sh` — they are retired stubs that refuse to run.
- [ ] Never deploy to `/home/mel`, `/home/mel/sites`, `/home/mel/shared`, or any
      parent — the guard will refuse, and so should you.
- [ ] Never `rsync --delete` application code.
