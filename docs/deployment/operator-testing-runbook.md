# Operator Testing Runbook

Step-by-step manual tests to run **on the server** after the one-time migration
(`server-layout.md`) and before trusting the pipeline. Nothing here is automated;
run each and confirm the expected result. These tests never touch production
infrastructure config.

Prerequisites: the three app dirs exist as git clones with `.mel-application`
markers, `/home/mel/shared/{backups,logs,deployments}` exist, and each site's
`settings.local.php` points at its own database.

---

## A. Hold deployment

1. **Dry run**
   `bash /home/mel/sites/myeventlane_hold/deploy/deploy-hold.sh --dry-run`
   - Expect: summary prints; **no** changes; exit 0.
2. **Deploy**
   `bash /home/mel/sites/myeventlane_hold/deploy/deploy-hold.sh`
   - Confirm the environment prompt with `hold`.
   - Expect: `Verification: PASS`, deployment report printed.
3. **Hold works** — load the Hold site; `/admin/config/myeventlane/analytics/status` is green.
4. **Staging untouched** — `ls -la /home/mel/sites/myeventlane_staging` unchanged; staging site still loads; its `git rev-parse HEAD` is unchanged.
5. **`public_html` unchanged** — `ls -l /home/mel/public_html` still points at `myeventlane_hold/web` (symlink not modified).
6. **Journal written** — newest file in `/home/mel/shared/deployments/` has `"application":"myeventlane_hold"` and `"deployment_result":"PASS"`.
7. **Verifier passes** — `bash /home/mel/sites/myeventlane_hold/deploy/verify-deployment.sh /home/mel/sites/myeventlane_hold` → `Overall: PASS`, exit 0.

## B. Staging deployment

1. `bash /home/mel/sites/myeventlane_staging/deploy/deploy-staging.sh --dry-run` then without `--dry-run` (confirm with `staging`).
2. **Hold untouched** — Hold's `git rev-parse HEAD` and site unchanged.
3. **Staging works** — staging site loads.
4. **Journal written** — newest journal has `"application":"myeventlane_staging"`.
5. **Verifier passes** — `bash …/myeventlane_staging/deploy/verify-deployment.sh /home/mel/sites/myeventlane_staging` → PASS.

---

## C. Failure tests — deployment must abort BEFORE any filesystem change

Run each and confirm a non-zero exit and **no** git pull / composer / drush ran.

| Test | Command | Expected |
|---|---|---|
| Shared home | `bash deploy/deploy-hold.sh` after editing `DEPLOY_PATH` to `/home/mel` (do **not** commit) | `Refusing to deploy to forbidden path: /home/mel` |
| Staging root | target `/home/mel/staging` | forbidden path refusal |
| Sites root | target `/home/mel/sites` | forbidden path refusal |
| Wrong marker | put `myeventlane_staging` in `/home/mel/sites/myeventlane_hold/.mel-application`, run `deploy-hold.sh` | `Identity mismatch … expected 'myeventlane_hold'` |
| Wrong document root | rename `web/` in the hold app, run `deploy-hold.sh` | `Web root … not a Drupal docroot` |

In every case: exit ≠ 0, no backup taken, no code changed. Restore the marker /
`web/` after testing.

> The forbidden-path cases are also covered automatically by the guard unit test
> (17/17). These manual runs confirm the wired-up scripts behave identically.

## D. Rollback tests

1. **Passes on correct application**
   `bash /home/mel/sites/myeventlane_hold/deploy/rollback-hold.sh --list` then
   `bash /home/mel/sites/myeventlane_hold/deploy/rollback-hold.sh` (confirm `hold`).
   - Expect: restores code + database from the latest Hold backup; verifier PASS.
2. **Fails on incorrect application** — pass a Staging backup dir to the Hold
   rollback:
   `bash …/rollback-hold.sh /home/mel/shared/backups/myeventlane_staging/<ts>`
   - Expect: `Refusing: backup … is not under this app's namespace …`; nothing changes.
3. **Never touches siblings** — after a Hold rollback, Staging's `git rev-parse
   HEAD`, files, and database are unchanged.
4. **Empty backup set** — with no backups present, `rollback-hold.sh` exits with
   `No backups found …` (never operates on the namespace root).

---

## Sign-off

Record in the deployment journal directory (or your change log): date, operator,
which tests passed, and the commit each app was left on. Only mark the pipeline
"trusted" once A, B, C, and D all pass.
