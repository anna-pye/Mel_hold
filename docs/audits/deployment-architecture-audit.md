# Deployment Architecture Audit

**Repository:** `Mel_hold` (`myeventlane_hold`)
**Date:** 2026-07-08
**Trigger:** A production deploy destroyed both the Hold site and the Staging site on the shared server.
**Scope:** Every deployment script, path, rsync/git flow, environment variable, settings file, and the parallel `mel-deployment/` framework.

> This audit is the required first step. No server-side change is proposed or executed here. All findings are derived from files in the repository, verified by reading each one — nothing is assumed.

---

## 1. Executive summary

The active deployment mechanism (`deploy/push-and-deploy.sh`) **rsyncs the repository into the shared home directory `/home/mel/` with `--delete`**. Because the Staging site lives at `/home/mel/staging` (a sibling *inside* that directory), `--delete` removed everything under `/home/mel/` that is not in the Hold repository — deleting Staging outright and overwriting Hold in place. A `mel_assert_not_staging_path` guard exists but is **never called by the script that actually deploys**.

The root causes are architectural, not a typo:

1. The deployment **target is a shared parent directory** (`/home/mel`), not an isolated application directory.
2. Deployment uses **`rsync --delete`**, which is destructive by design.
3. The deployment target is **generic and user/env-supplied** (`MEL_DEPLOY_PATH`), so one script can point anywhere.
4. There is **no backup, no rollback, no dry-run, and no confirmation**.

There is also a second, **contradictory** deployment concept in the repo (`mel-deployment/`) that is documentation-only and is not wired to anything. See §6.

---

## 2. Inventory (what exists today)

### 2.1 `deploy/` scripts

| File | Role | Runs where | Guard sourced? |
|---|---|---|---|
| `push-and-deploy.sh` | **Primary deploy.** Pushes `main`, then `rsync --delete` repo → `${MEL_DEPLOY_PATH}` (default `/home/mel`), then runs remote post-deploy. Also supports a `git` method. | Developer Mac → server | **No** — does not source `deploy-common.sh`; the staging guard never runs on the rsync path. |
| `cpanel-post-deploy.sh` | Post-deploy: `composer install --no-dev`, `drush updatedb`, `drush cr`. | Server (in the synced dir) | No |
| `site-deploy.sh` | Post-deploy for the `git` method: env check, composer, **can run `drush site:install`** if drush fails to bootstrap. | Server | Yes |
| `post-deploy-verify.sh` | Verification: drush status/config:status/maintenance/sitemap. | Server | Yes |
| `check-mel-environment.sh` | Validates required env vars (never prints secrets). | Server | Yes |
| `deploy-common.sh` | Shared helpers: `mel_assert_not_staging_path`, `mel_load_production_env`. | sourced | — |
| `deploy.env` | **Live config:** `MEL_DEPLOY_PATH=/home/mel`, `MEL_DEPLOY_METHOD=rsync`, host, user. | — | — |
| `deploy.env.example` | Template for the above. | — | — |
| `rsync-exclude.txt` | Excludes for the rsync (`.git`, `vendor/`, `.env*`, `web/sites/default/files/`, `settings.local.php`, `settings.production.php`, …). | — | — |
| `production.env.example` | Template for `/etc/myeventlane/production.env`. | — | — |
| `bootstrap-debian-ubuntu.sh`, `nginx-myeventlane.conf`, `php-fpm-99-clear-env.conf`, `systemd/…` | VPS provisioning artefacts (not cPanel). | — | — |

### 2.2 GitHub workflows

- **None** in the application repo (`.github/` does not exist). There is no CI/CD auto-deploy. Deployment is entirely manual.
- `mel-deployment/.github/workflows/validate.yml` exists but belongs to the (inert) framework in §6 and performs validation only.

### 2.3 Composer scripts

- `composer.json` has **no `scripts` section** — no deploy hooks live in Composer.

### 2.4 Environment / settings

- `settings.php` load order (verified, lines 943–954): `settings.php` → `settings.production.php` → `settings.local.php` → `settings.ddev.php`.
- `.gitignore` excludes `settings.local.php`, `settings.production.php`, `web/sites/*/files/`, `web/sites/*/private/`, `/vendor/`, `.env*`, `deploy/deploy.env`, `etc/myeventlane/*.env`. **Secrets and per-environment settings are correctly kept out of Git.**
- Secrets are injected at runtime via env vars in `settings.php` (`POSTMARK_API_KEY`, `MEL_GIT_COMMIT`, GA4/Search Console, DB) and via the gitignored `settings.*.php`.

---

## 3. Data-flow of the failed deployment (verified)

```
push-and-deploy.sh
  git push origin main
  rsync -avz --delete --exclude-from=rsync-exclude.txt  ./  mel@host:/home/mel/
                     ^^^^^^^^                                 ^^^^^^^^^^
                     destructive                             SHARED HOME DIRECTORY
  ssh host chown -R mel:mel /home/mel        # recursive chown of the whole home dir
  ssh host bash deploy/cpanel-post-deploy.sh /home/mel
```

`/home/mel/` contained at least:

```
/home/mel/
  web/  vendor?  composer.json  …      <- Hold (overwritten in place)
  staging/                             <- Staging (NOT in repo, NOT excluded) -> DELETED
  <other home-dir contents>           <- mail, dotfiles, public_html, … -> at risk of deletion
```

`--delete` removes anything in the destination not present in the source. Staging was collateral.

---

## 4. Unsafe behaviours found (complete list)

| # | Severity | Finding | Evidence |
|---|---|---|---|
| U1 | **Critical** | Deploy target is a **shared parent** (`/home/mel`), not an isolated app dir. | `deploy.env: MEL_DEPLOY_PATH=/home/mel` |
| U2 | **Critical** | `rsync --delete` against that shared dir deletes sibling sites and unrelated home content. | `push-and-deploy.sh:81` |
| U3 | **Critical** | The staging guard is **not invoked** by the primary deploy script (it never sources `deploy-common.sh`). | `push-and-deploy.sh` (no `source`) |
| U4 | **High** | The guard only forbids `/home/mel/staging*`; it **allows `/home/mel` itself** — the actual disaster path. | `deploy-common.sh` |
| U5 | **High** | **Generic, env-supplied target** (`MEL_DEPLOY_PATH`) — one script can be pointed at any path. | `push-and-deploy.sh:37` |
| U6 | **High** | **One script deploys any site** — no per-application isolation. | single `push-and-deploy.sh` |
| U7 | **High** | **No pre-deploy backup** anywhere (code or database). Confirmed: no `tar`/`mysqldump`/`sql:dump`/`cp -r` in `deploy/`. | grep of `deploy/` |
| U8 | **High** | **No rollback** mechanism of any kind. | — |
| U9 | **Medium** | **No dry-run** and **no confirmation prompt** — the destructive rsync runs immediately. | `push-and-deploy.sh` |
| U10 | **Medium** | `chown -R mel:mel /home/mel` recursively re-owns the entire home directory. | `push-and-deploy.sh:86` |
| U11 | **Medium** | `site-deploy.sh` can invoke `drush site:install` when drush fails to bootstrap — a broken `settings.php` could trigger a **fresh reinstall over an existing site**. | `site-deploy.sh` (site:install branch) |
| U12 | **Medium** | `settings.php` is **not** in `rsync-exclude.txt`, so the repo's placeholder `settings.php` overwrites the server's on every rsync. (Secrets survive only because `settings.local/production.php` are excluded.) | `rsync-exclude.txt` |
| U13 | **Low** | No git validation before deploy (clean tree, `--ff-only`, expected remote/branch) beyond a plain `checkout`/`pull`. | `push-and-deploy.sh:56-63` |
| U14 | **Low** | Two contradictory deployment concepts coexist (`deploy/` vs `mel-deployment/`), increasing operator confusion. | §6 |

---

## 5. What survived (recovery context)

- **Databases** — rsync only touched files under `/home/mel`; MySQL/MariaDB data lives in the server datadir. **Both databases are intact.**
- **Hold `files/`** — `web/sites/default/files/` is in `rsync-exclude.txt`; Hold's uploads were preserved.
- **Hold secrets** — `settings.local.php` / `settings.production.php` are excluded; if present on the server they survived.
- **All code** — recoverable from Git and from the developer's DDEV machine.

Lost: `/home/mel/staging/*` (code, its `files/`, its settings) and any non-repo, non-excluded content directly under `/home/mel`.

---

## 6. The `mel-deployment/` framework (conflict)

`mel-deployment/` is tracked **inside** this repo (25 files, no submodule) and describes a **release-based, manifest-driven** deployment model. It is important to be precise about what it is:

- It is **documentation + a local manifest-validation framework only.** Its own README states: *"No executable deployment scripts… No SSH, rsync, Composer, or Drush execution."*
- It **intentionally leaves concrete server paths undefined** and is **fail-closed on missing inputs.**
- Its contract states: *"Paths must not be hardcoded in deployment scripts… Paths must come from validated manifests."*

**This directly contradicts the requested architecture**, which requires three scripts that **hardcode** their target path and forbid any generic/manifest-supplied target. The two models cannot both govern the same scripts.

**Resolution (recommended):** adopt the **explicit-hardcoded-script** model for the immediate safety fix (this refactor), because it is simpler to review and impossible to misuse, and treat `mel-deployment/`'s immutable-release/manifest model as a **separate, future** evolution. The new `deploy/` scripts and `mel-deployment/` are kept from colliding: the new scripts never read a manifest, and `mel-deployment/` remains non-executable. This is a decision the maintainer must ratify — flagged, not silently merged.

---

## 7. Assumptions that could NOT be verified (no server access)

Per the rules, these are **stopped on** and handed off as manual steps (see `docs/deployment/server-layout.md`), not guessed:

1. The current live docroot (`/home/mel/web` vs `/home/mel/public_html`) — unverifiable from the repo.
2. Whether Staging is the same cPanel account or a separate one.
3. Real database names / users for each environment.
4. PHP-FPM pool users and Apache vhost/docroot configuration.
5. Whether the new `/home/mel/sites/*` + `/home/mel/shared/*` layout exists yet (it does **not** in the repo's world; it must be created server-side).

Because migrating to the new layout **requires destructive server-side moves and symlink/docroot changes that cannot be safely inferred**, those steps are documented as an operator runbook and are explicitly **out of scope for automated execution** in this change.

---

## 8. Requirements mapping (how the refactor answers each finding)

| Finding | Fixed by |
|---|---|
| U1, U5, U6 | Three scripts, each **hardcoding** exactly one allowlisted app path; no generic target. |
| U2, U12 | **Git-based** in-place deploy (`pull --ff-only`); **no `rsync --delete`** for code. `files/`/settings are gitignored and never touched. |
| U3, U4 | Fail-closed **allowlist** guard (only the three app dirs) + explicit forbidden-parent refusal, invoked at the top of every script. |
| U7 | **Pre-deploy backup**: code archive + `drush sql:dump` to `/home/mel/shared/backups/<app>/`. |
| U8 | **Per-app rollback** script: restore previous commit + database + files. |
| U9 | `--dry-run` mode and an interactive **confirmation** + deployment summary. |
| U10 | Ownership is scoped to the app dir only (never the home dir). |
| U11 | New scripts never call `site:install`; they abort if the site cannot bootstrap. |
| U13 | Git validation: expected remote/branch, clean tree, `--ff-only`. |
| U14 | Legacy destructive scripts neutralised; `mel-deployment/` explicitly scoped as future. |

See `docs/deployment/architecture.md` for the target design and `docs/deployment/server-layout.md` for the manual migration runbook.
