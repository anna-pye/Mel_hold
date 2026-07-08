# Server Layout & Migration Runbook

> **This document describes server-side state and a MANUAL migration. None of it
> is executed by any script in this repo.** The migration moves live sites and
> repoints web roots; it requires a human operator with server access, a
> maintenance window, and a verified backup. Do not automate it.

## Target layout

```
/home/mel/
    sites/
        myeventlane_hold/          # git clone; web root at ./web
        myeventlane_staging/       # git clone; web root at ./web
        myeventlane_production/    # git clone; web root at ./web  (future)
    shared/
        backups/                   # <app>/<timestamp>/{code.tgz,database.sql.gz,PREVIOUS_COMMIT}
        logs/                      # <app>/deploy-<timestamp>.log
        monitoring/                # reserved for uptime/health artefacts
```

Each application directory is fully self-contained: its own `.git`, `vendor/`,
`config/sync`, `web/sites/default/settings.local.php`, private files, and
`web/sites/default/files`.

## Web roots (symlinks — never modified by deploy scripts)

| Public path | Points to |
|---|---|
| `public_html` | `/home/mel/sites/myeventlane_hold/web` |
| `staging` (docroot/subdomain) | `/home/mel/sites/myeventlane_staging/web` |
| production docroot (future) | `/home/mel/sites/myeventlane_production/web` |

Symlinks and Apache/cPanel document roots are **operator-managed**. No deploy
or rollback script creates, changes, or follows these symlinks.

## Current state (pre-migration) — unverified from the repo

The audit could not verify these from the repository; confirm on the server
before migrating:

- Hold currently lives at `/home/mel` (deployed there by the retired rsync).
- Staging previously lived at `/home/mel/staging` and was deleted in the incident.
- Real database names/users per environment.
- Whether the live docroot is `/home/mel/web` or `/home/mel/public_html`.

## Migration runbook (manual, one-time)

Perform in a maintenance window. Take a full cPanel/JetBackup snapshot first.

1. **Create the layout**
   ```
   mkdir -p /home/mel/sites /home/mel/shared/{backups,logs,monitoring}
   ```

2. **Move Hold into its own directory**
   - Clone: `git clone git@github.com:anna-pye/Mel_hold.git /home/mel/sites/myeventlane_hold`
   - Copy the *runtime* data from the current Hold into it:
     - `web/sites/default/settings.local.php` (real DB creds — never from Git)
     - `web/sites/default/files/`
     - any `private/` files
   - `cd /home/mel/sites/myeventlane_hold && composer install --no-dev`
   - **Create the deployment identity marker** (required by preflight; gitignored):
     ```
     echo 'myeventlane_hold' > /home/mel/sites/myeventlane_hold/.mel-application
     ```
   - Verify: `vendor/bin/drush status` shows *Successful* and the correct DB.

3. **Recreate Staging — owned by the mel-deployment repository**
   - Staging is **not** part of Mel_hold. Clone and provision it per the
     `github.com/anna-pye/mel-deployment` repository's own runbook (its source
     repo, its `settings.local.php`, its `.mel-application` marker).
   - This step is listed only so the operator restores staging in the same
     window; the authoritative instructions live in that repository.
   - From Mel_hold's side, only confirm isolation: deploying Hold must not touch
     `/home/mel/sites/myeventlane_staging`.

4. **Repoint web roots** (operator)
   - `public_html` → `/home/mel/sites/myeventlane_hold/web`
   - staging docroot → `/home/mel/sites/myeventlane_staging/web`
   - Confirm Apache/cPanel document roots match.

5. **Validate isolation**
   - Hold (this repo): `cd /home/mel/sites/myeventlane_hold && ./deploy/deploy-hold.sh --dry-run`
   - Staging (mel-deployment repo): dry-run its own script per that repo's runbook.
   - Confirm each summary shows the correct, isolated path and database, and that
     the Hold script **refuses** the staging path.

6. **Remove the old `/home/mel/web`** (only after both sites verify) — operator
   decision; keep a backup.

## Deployment identity marker (`.mel-application`)

Every application directory must contain a one-line, gitignored file naming the
environment it serves:

```
/home/mel/sites/myeventlane_hold/.mel-application        -> myeventlane_hold
/home/mel/sites/myeventlane_staging/.mel-application     -> myeventlane_staging   (owned by mel-deployment)
```

Hold is deployed from `Mel_hold`; staging is deployed from the separate
`mel-deployment` repository — the two app directories are **different
repositories**, not clones of one. The marker is the reliable proof that the
checkout at a given path is the intended application: the Hold script's preflight
**fails closed** unless `.mel-application` reads `myeventlane_hold` — so a staging
checkout accidentally placed at the Hold path is caught *before* any file is
touched. The Hold marker is created once, here,
during migration.

## Why this cannot be scripted here

Steps 2–6 move live data and change document roots based on facts only visible
on the server (current docroot, DB names, symlink targets). Guessing any of
them risks a second outage, so they are documented for a human, per the audit's
STOP condition.
