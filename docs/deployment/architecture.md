# Deployment Architecture

**Status:** active design (supersedes the retired `deploy/push-and-deploy.sh` rsync flow).
**Goal:** total isolation between MEL Hold, Staging, and future Production. A deployment of one application must never be able to read, write, or delete another.

## Invariants

1. A Hold deployment cannot affect Staging.
2. A Staging deployment cannot affect Hold.
3. A Production deployment cannot affect Hold or Staging.
4. No deployment script can target a parent directory.
5. No deployment can remove another application's files.
6. No deployment can overwrite environment-specific configuration (`settings.local.php` / `settings.production.php`).
7. No deployment can expose secrets (secrets are gitignored and env-injected).
8. Every deployment is repeatable, auditable, reversible, and idempotent.
9. Every deployment can be rolled back with one documented command.

## How the invariants are enforced

| Invariant | Mechanism |
|---|---|
| 1–3, 5 | Each app has its **own** script that **hardcodes** its `/home/mel/sites/<app>` path. Git-based, in place. No `rsync --delete`. Files/settings are gitignored, so `git pull` never touches sibling apps or runtime data. |
| 4 | `mel_guard_target_path` refuses `/`, `/home`, `/home/mel`, `/home/mel/sites`, `/home/mel/shared`, `/home/mel/staging` and anything not on the three-entry allowlist. |
| 6 | Deploy uses `git pull --ff-only`; `settings.local.php` / `settings.production.php` / `web/sites/*/files/` are gitignored and never in the pull. |
| 7 | Secrets live only in gitignored `settings.*.php` and env vars; never in the repo or scripts. |
| 8 | Idempotent steps (`pull --ff-only`, `composer install`, `updatedb`, `config:import`, `cache:rebuild`); clean-tree + branch + remote validation; `--dry-run`. |
| 9 | Pre-deploy backup (code + DB) + per-app `rollback-<app>.sh`. |

**Verification & journal.** Every deploy ends with `mel_verify` — 13 read-only
checks (correct application, document root, git clean, branch, commit, Composer,
Drupal bootstrap, database, maintenance mode, config, pending updates, cron,
watchdog) returning **PASS / WARNING / FAIL** — and writes a secret-free
`/home/mel/shared/deployments/<timestamp>.json` journal (application, environment,
branch, commit, build, versions, results). The same `mel_verify` powers the
standalone `deploy/verify-deployment.sh`, so there is one source of truth. A FAIL
exits non-zero with the rollback command. **Runtime assets:** files/ live inside
each app dir and are gitignored, so `git pull` never touches them; no rsync is
used for code, and any future asset sync must target only
`web/sites/default/files/` and never use `--delete` against an app directory.

**Preflight.** Before *any* filesystem change, deploy and rollback run
`mel_preflight`, which fails closed unless all of these hold: the target is the
allowlisted app dir; it is a git clone with `composer.json`; the web root is
exactly `<path>/web` and a real Drupal docroot (`index.php`); and a gitignored
`.mel-application` identity marker at the path matches the script's hardcoded
app name. Because all three environments clone the *same* repository, the marker
is what makes "am I operating on the intended application?" verifiable — a
misplaced clone is caught before a single file is touched.

## One application = one everything

```
one application  ->  /home/mel/sites/<app>
one repository   ->  github.com/anna-pye/Mel_hold  (each app is its own clone)
one deployment   ->  deploy/deploy-<app>.sh   (hardcoded, no shared target)
one web root     ->  /home/mel/sites/<app>/web
one config       ->  <app>/config/sync + <app>/web/sites/default/settings.*.php
one files dir    ->  <app>/web/sites/default/files  (gitignored, never touched)
```

## Component diagram

```
Developer Mac                         GitHub                    Server (cPanel)
─────────────                         ──────                    ───────────────
git push main  ───────────────────▶  Mel_hold ────┐
                                                   │  (each app pulls itself)
                                                   ▼
                                   /home/mel/sites/myeventlane_hold      (deploy-hold.sh)
                                   /home/mel/sites/myeventlane_staging   (deploy-staging.sh)
                                   /home/mel/sites/myeventlane_production (deploy-production.sh)
                                                   │
                                                   ▼
                                   /home/mel/shared/{backups,logs,monitoring}
```

Deployment is **pull-based on the server**: you SSH in and run the one script for the app you intend to deploy. The Mac only pushes to GitHub. There is no push-from-Mac rsync anymore.

## Relationship to `mel-deployment/`

`mel-deployment/` documents a future, manifest-driven, immutable-release model. Its contract explicitly forbids hardcoded paths. **This architecture deliberately chooses the opposite** — hardcoded, explicit, per-app scripts — because after the 2026-07-07 incident we optimise for *safety and reviewability over flexibility*. The two are not merged: these scripts never read a manifest, and `mel-deployment/` remains non-executable documentation. Adopting the immutable-release model later is a separate, deliberate project. See `docs/audits/deployment-architecture-audit.md` §6.
