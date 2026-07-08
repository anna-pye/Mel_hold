# Operator Testing Runbook

Step-by-step manual tests to run **on the server** after the one-time migration
(`server-layout.md`) and before trusting the pipeline. Nothing here is automated;
run each and confirm the expected result. These tests never touch production
infrastructure config.

Scope: this runbook covers **Hold** (owned by `Mel_hold`). Staging is owned by
the `mel-deployment` repository and tested from there; here we only confirm Hold
is isolated from it.

Prerequisites: the Hold app dir exists as a git clone with its
`.mel-application` = `myeventlane_hold` marker, `/home/mel/shared/{backups,logs,
deployments}` exist, and Hold's `settings.local.php` points at its own database.
(The staging app dir is provisioned separately per mel-deployment.)

---

## A. Hold deployment

1. **Dry run**
   `cd /home/mel/sites/myeventlane_hold && ./deploy/deploy-hold.sh --dry-run`
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

## B. Staging deployment (owned by the mel-deployment repository)

> Staging is **not** deployed from Mel_hold. It is deployed with the tooling in
> github.com/anna-pye/mel-deployment. Run staging's own script per that repo's
> runbook. Mel_hold has no `deploy-staging.sh`, and its guard refuses the staging
> path. The checks below are the **isolation expectations** to confirm from the
> Hold side after a staging deploy:

1. Deploy staging using the `mel-deployment` repo's script (see its docs).
2. **Hold untouched** — Hold's `git rev-parse HEAD`, files, database, and site are unchanged.
3. **Staging works** — staging site loads (verified with mel-deployment's own verifier).
4. **`public_html` unchanged** — still points at `myeventlane_hold/web`.

---

## C. Failure tests — deployment must abort BEFORE any filesystem change

Run each and confirm a non-zero exit and **no** git pull / composer / drush ran.

Run these directly against the guard (safe, no changes) or via the wired script:

| Test | Command | Expected |
|---|---|---|
| Shared home | `bash -c 'source deploy/lib/mel-deploy-guards.sh; mel_guard_target_path /home/mel'` | `Refusing to deploy to forbidden path: /home/mel` |
| Staging **application** | `… mel_guard_target_path /home/mel/sites/myeventlane_staging` | refusal naming **mel-deployment** (a Mel_hold script must never deploy staging) |
| Production application | `… mel_guard_target_path /home/mel/sites/myeventlane_production` | refusal naming mel-deployment |
| Staging root | `… mel_guard_target_path /home/mel/staging` | forbidden path refusal |
| Sites root | `… mel_guard_target_path /home/mel/sites` | forbidden path refusal |
| Wrong directory | run `./deploy/deploy-hold.sh` from anywhere other than the Hold app dir | `Run this from /home/mel/sites/myeventlane_hold` |
| Wrong marker | put `myeventlane_staging` in Hold's `.mel-application`, run `deploy-hold.sh` | `Identity mismatch … expected 'myeventlane_hold'` |
| Wrong document root | rename `web/` in the Hold app, run `deploy-hold.sh` | `Web root … not a Drupal docroot` |

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
