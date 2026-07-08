# Backup Strategy

Every deployment takes a backup **before it changes anything**. This is
automatic and non-optional — it runs inside each `deploy-<app>.sh` prior to the
first `git pull`.

## What is captured

For each run, under `/home/mel/shared/backups/<app>/<YYYYmmdd-HHMMSS>/`:

| File | Contents | Used for |
|---|---|---|
| `PREVIOUS_COMMIT` | The commit the app was on before deploy. | Git rollback (`git reset --hard`). |
| `code.tgz` | App code excluding `vendor/`, `web/sites/default/files/`, `.git/`. | Inspect/restore code if git history is unavailable. |
| `database.sql.gz` | `drush sql:dump --gzip` of the app's own database. | Database rollback. |

The database dump uses the application's **own** drush and therefore its own
credentials — no credentials are hardcoded anywhere.

### Fail-closed

The backup is not best-effort. If the commit marker, code archive, or database
dump cannot be produced, the deploy **aborts before any change** — you never get
a "successful" deploy with no way back. The one exception is a genuine first
deploy where the site has no database yet: that requires an explicit
`MEL_ALLOW_NO_BACKUP=1` on the command, and it is logged.

## Isolation

Backups are namespaced per application. This repo writes only
`backups/myeventlane_hold/…` (staging backups are owned by the mel-deployment
repo under its own namespace). One app's deploy or rollback never reads or writes
another app's backups.

## What is NOT backed up (and why it is safe)

- `web/sites/default/files/` — never modified by a git deploy, so no pre-deploy
  snapshot is needed for a code release. Use cPanel/JetBackup for file-level
  disaster recovery.
- `vendor/` — reproducible from `composer install`.

## Retention policy

- Keep the **last 10** backups per application, plus **any backup from the last
  14 days**.
- Pruning is a scheduled operator task (cron), not part of the deploy path, so a
  deploy can never delete a backup:
  ```
  # Example monthly prune, per app (operator cron; review before enabling):
  ls -1dt /home/mel/shared/backups/myeventlane_hold/*/ | tail -n +11 | xargs -r rm -rf
  ```
- Off-server copies (cPanel/JetBackup or an object store) remain the source of
  truth for disaster recovery; these on-server backups are for fast rollback.

## Restore

See `docs/deployment/rollback.md`. In short: `rollback-<app>.sh` restores the
latest (or a named) backup — code + database — for that app only.
