# Deployment Flow

Deployment is **git-based and pull-based on the server**. You push to GitHub
from your Mac, then SSH to the server and run the one script for the app you are
deploying. There is no rsync and no generic script.

## Everyday flow

```
# 1. On your Mac: merge to main and push (via PR as usual).
git push origin main

# 2. SSH to the server.
ssh mel@myeventlane.com.au

# 3. Deploy exactly one app. Preview first with --dry-run.
bash /home/mel/sites/myeventlane_hold/deploy/deploy-hold.sh --dry-run
bash /home/mel/sites/myeventlane_hold/deploy/deploy-hold.sh
```

Staging and Production are identical with their own scripts:

```
bash /home/mel/sites/myeventlane_staging/deploy/deploy-staging.sh
MEL_ALLOW_PRODUCTION_DEPLOY=1 bash /home/mel/sites/myeventlane_production/deploy/deploy-production.sh
```

## What each deploy script does (in order)

```
preflight                           # BEFORE any filesystem change, fail closed on:
  · path      -> allowlisted, exactly this app's dir (not a parent/sibling)
  · app dir   -> exists, is a git clone with composer.json
  · web root  -> exactly <path>/web and a real Drupal docroot (index.php)
  · identity  -> .mel-application marker matches this script's app name
validate git                        # branch == main, origin == Mel_hold, clean tree, fetch
print deployment summary            # env, path, web root, branch, local+remote commit, DB
--dry-run? -> stop here
confirm                             # type the environment name (production: also env-gated)
backup                              # code.tgz + drush sql:dump -> shared/backups/<app>/<ts>/
git checkout main
git pull --ff-only origin main      # never a merge; never rsync --delete
composer install --no-dev
drush updatedb -y
drush config:import -y
drush cache:rebuild
health checks                       # bootstrap OK, not in maintenance mode
write deploy log                    # shared/logs/<app>/deploy-<ts>.log
```

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
