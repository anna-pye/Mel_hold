# Deployment Architecture

**Status:** active design (supersedes the retired `deploy/push-and-deploy.sh` rsync flow).
**Scope:** This repository (`Mel_hold`) deploys **only the Hold application**. Staging / main MyEventLane are owned by a **separate** repository, github.com/anna-pye/mel-deployment. See [repository-ownership.md](repository-ownership.md). Nothing in this repo can deploy or modify staging.
**Goal:** total isolation — a Hold deployment must never be able to read, write, or delete staging (or any sibling), and vice versa.

## Invariants

1. A Hold deployment cannot affect Staging (or any sibling application).
2. A Staging deployment (from the mel-deployment repo) cannot affect Hold.
3. Mel_hold contains no staging/production deploy scripts; those paths are forbidden here.
4. No deployment script can target a parent directory.
5. No deployment can remove another application's files.
6. No deployment can overwrite environment-specific configuration (`settings.local.php` / `settings.production.php`).
7. No deployment can expose secrets (secrets are gitignored and env-injected).
8. Every deployment is repeatable, auditable, reversible, and idempotent.
9. Every deployment can be rolled back with one documented command.

## How the invariants are enforced

| Invariant | Mechanism |
|---|---|
| 1–3, 5 | The Hold script **hardcodes** its `/home/mel/sites/myeventlane_hold` path. Git-based, in place. No `rsync --delete`. Files/settings are gitignored, so `git pull` never touches sibling apps or runtime data. |
| 4 | `mel_guard_target_path` allowlists **only** `/home/mel/sites/myeventlane_hold` and refuses `/`, `/home`, `/home/mel`, `/home/mel/sites`, `/home/mel/shared`, `/home/mel/staging`, and the `myeventlane_staging` / `myeventlane_production` paths (owned by mel-deployment). |
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
app name. Deploy and rollback additionally refuse to run unless the **current
directory** is the Hold app dir (`mel_require_cwd`), matching the operator
runbook (`cd` first). The marker makes "am I operating on the intended
application?" verifiable — a misplaced clone is caught before a file is touched.

## One application = one everything (Hold, in this repo)

```
one application  ->  /home/mel/sites/myeventlane_hold
one repository   ->  github.com/anna-pye/Mel_hold        (Hold only)
one deployment   ->  deploy/deploy-hold.sh               (hardcoded, Hold only)
one web root     ->  /home/mel/sites/myeventlane_hold/web
one config       ->  config/sync + web/sites/default/settings.*.php
one files dir    ->  web/sites/default/files             (gitignored, never touched)
one marker       ->  .mel-application = myeventlane_hold
```

Staging / main MyEventLane is the mirror of this, owned by the
`mel-deployment` repository — its own script, path, marker, and docs.

## Component diagram

```
Developer Mac              GitHub                         Server (cPanel)
─────────────              ──────                         ───────────────
git push main ─▶  Mel_hold (Hold) ──────────▶ /home/mel/sites/myeventlane_hold      (deploy-hold.sh)
git push main ─▶  mel-deployment (Staging) ─▶ /home/mel/sites/myeventlane_staging   (its own script)
                                                          │
                                                          ▼
                                   /home/mel/shared/{backups,logs,deployments}
```

Deployment is **pull-based on the server**: you SSH in, `cd` into the app dir, and run **that app's** script. The Mac only pushes to GitHub. Hold is driven by this repo's `deploy-hold.sh`; staging is driven by the `mel-deployment` repo's own script. There is no push-from-Mac rsync anymore, and no script here can reach staging.

## Relationship to the `mel-deployment/` directory vs the mel-deployment repo

Two different things share the name:

- The **`mel-deployment/` directory** in this repo is a vendored, non-executable copy of a manifest-driven, immutable-release *specification*. It deploys nothing.
- The **`github.com/anna-pye/mel-deployment` repository** is the live deployment tooling for staging / main MyEventLane.

This Hold architecture deliberately uses hardcoded, explicit scripts (safety and reviewability over flexibility) rather than the manifest model. The two are not merged; `deploy-hold.sh` never reads a manifest. See [repository-ownership.md](repository-ownership.md).
