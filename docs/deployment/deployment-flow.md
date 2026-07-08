# Deployment Flow

Deployment is **git-based and pull-based on the server**. You push to GitHub
from your Mac, then SSH to the server, `cd` into the app directory, and run that
app's script. There is no rsync and no generic script.

**This repository (`Mel_hold`) deploys only Hold.** Staging / main MyEventLane is
owned by github.com/anna-pye/mel-deployment and is deployed with *its* script —
never from here. See [repository-ownership.md](repository-ownership.md).

## Everyday flow — Hold

```
# 1. On your Mac: merge to main and push (via PR as usual).
git push origin main

# 2. SSH to the server.
ssh mel@myeventlane.com.au

# 3. cd into the Hold app dir (required — the script refuses otherwise) and deploy.
cd /home/mel/sites/myeventlane_hold
./deploy/deploy-hold.sh --dry-run     # preview
./deploy/deploy-hold.sh               # deploy
```

## Staging (owned by the mel-deployment repository)

```
cd /home/mel/sites/myeventlane_staging
./deploy/deploy-staging.sh            # provided by github.com/anna-pye/mel-deployment
```

There is no `deploy-staging.sh` in Mel_hold, and the Hold guard refuses the
staging path outright.

## What each deploy script does (in order)

```
require cwd                         # refuse unless you are IN the Hold app dir
preflight                           # BEFORE any filesystem change, fail closed on:
  · path      -> allowlisted = ONLY /home/mel/sites/myeventlane_hold
  · app dir   -> exists, is a git clone with composer.json
  · web root  -> exactly <path>/web and a real Drupal docroot (index.php)
  · identity  -> .mel-application marker == myeventlane_hold
validate git                        # branch == main, origin == Mel_hold, clean tree, fetch
print deployment summary            # env, path, web root, branch, local+remote commit, DB
--dry-run? -> stop here
confirm                             # type the environment name (production: also env-gated)
backup                              # code.tgz + drush sql:dump -> shared/backups/<app>/<ts>/
git checkout main
git pull --ff-only origin main      # never a merge; never rsync --delete
composer install --no-dev --optimize-autoloader
drush updatedb -y
drush config:import -y
drush cache:rebuild
verify                              # mel_verify: 13 read-only checks (below)
write deployment journal            # shared/deployments/<ts>.json
print deployment report             # app/env/branch/commit/build/versions/duration/result
write deploy log                    # shared/logs/<app>/deploy-<ts>.log
```

### Deployment verification (`mel_verify`)

The same function runs at the end of every deploy and standalone via
`deploy/verify-deployment.sh <app path>`. It makes **no** changes and returns an
overall **PASS / WARNING / FAIL**:

| FAIL (hard) | WARNING (soft) |
|---|---|
| application marker mismatch | git working tree not clean |
| document root missing | HEAD ≠ origin/main |
| not on `main` | config drift |
| Composer not installed | pending database updates |
| Drupal does not bootstrap | cron never run |
| database not reachable | watchdog not readable |
| site in maintenance mode | |

A deploy whose verification is FAIL exits non-zero and prints the rollback
command. WARNING is non-blocking (deploy succeeds, warnings shown).

### Deployment journal

Every deploy writes `/home/mel/shared/deployments/YYYY-MM-DD-HHMMSS.json` with:
application, environment, branch, commit, build number, timestamp, hostname,
deploy user, deployment type (`git`), web root, preflight result, deployment
(verification) result, rollback availability, and Composer/Drupal/PHP versions.
**No secrets** are stored. Build numbers are a monotonic per-app counter.

On any failure the script prints the exact rollback command with the backup
directory it created.

## Safety guarantees

- **Isolation:** the path is hardcoded and allowlisted; a Hold deploy physically
  cannot resolve to Staging/Production or a parent.
- **No destructive sync:** git in place, `--ff-only`; runtime data (`files/`,
  `settings.*.php`) is gitignored and untouched.
- **Reversible:** a backup is taken before the first change on every run.
- **Idempotent:** re-running with no new commits just re-runs build steps.
- **Auditable:** every run appends to a timestamped log under `shared/logs/`.

## Production extra gates

- `--yes` is rejected — confirmation is always interactive.
- Requires `MEL_ALLOW_PRODUCTION_DEPLOY=1` in the environment.
- Prompts for the literal word `production`.

## Flags

| Flag | Effect |
|---|---|
| `--dry-run` | Validate + show the summary, then stop. No backup, no changes. |
| `--yes` | Skip the interactive confirmation (Hold/Staging only). |
