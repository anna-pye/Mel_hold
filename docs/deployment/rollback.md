# Rollback

Each application has one rollback script. It restores that app's most recent
(or a named) pre-deploy backup — **code and database** — and touches nothing
else.

## One-command rollback

```
# Roll the app back to its latest pre-deploy backup:
bash /home/mel/sites/myeventlane_hold/deploy/rollback-hold.sh
bash /home/mel/sites/myeventlane_staging/deploy/rollback-staging.sh
bash /home/mel/sites/myeventlane_production/deploy/rollback-production.sh
```

The deploy scripts also print the exact rollback command (with the backup
directory) whenever a deploy fails.

## Choosing a specific backup

```
# List available backups for the app:
bash deploy/rollback-hold.sh --list

# Roll back to a specific one:
bash deploy/rollback-hold.sh /home/mel/shared/backups/myeventlane_hold/20260708-101500
```

## What rollback does

```
guard target path (allowlist)     # same fail-closed guard as deploy
confirm                           # type the environment name
git reset --hard <PREVIOUS_COMMIT>  # from the backup marker
composer install --no-dev
restore database from database.sql.gz  (drush sql:cli)
drush cache:rebuild
```

## What rollback does NOT do

- It does **not** modify symlinks or document roots (operator-managed).
- It does **not** touch `web/sites/default/files/` (never changed by deploy).
- It does **not** affect any other application.

## If there is no backup

If the DB dump is missing (e.g. drush could not bootstrap at deploy time), the
script restores code only and leaves the database unchanged, and says so. Fall
back to cPanel/JetBackup for a full restore. This is why an off-server backup
remains the disaster-recovery source of truth (`backup-strategy.md`).

## Post-rollback

1. Confirm `drush status` is *Successful* and the site loads.
2. Diagnose the failed release before re-attempting a deploy.
3. Only re-deploy once `current` is confirmed healthy.
