# Deployment Final Audit

**Repository:** `Mel_hold` (`myeventlane_hold`)
**Date:** 2026-07-08
**Phase:** Final deployment hardening (git-first, journal, verification).
**Predecessor:** `docs/audits/deployment-architecture-audit.md` (the incident postmortem + isolation redesign).

> Audit-first, no server changes. This records what the repository already
> satisfies (accepted, not duplicated) and what this phase adds.

> **Repository split (authoritative):** `Mel_hold` deploys **only Hold**. Staging
> / main MyEventLane is owned by the separate `github.com/anna-pye/mel-deployment`
> repository. This repo's guard allowlists only the Hold path and forbids the
> staging/production paths; there are no staging/production scripts here. See
> [../deployment/repository-ownership.md](../deployment/repository-ownership.md).
> (Earlier drafts modelled staging/production as clones of Mel_hold — that is
> superseded.)

## 1. What already satisfies the requirements (accepted, not re-implemented)

| Requirement | Status | Evidence |
|---|---|---|
| Git is the canonical deployment | **Accepted** | `deploy/deploy-{hold,staging,production}.sh` use `git fetch`/`checkout`/`pull --ff-only` then `composer install --no-dev` + `drush updatedb`/`config:import`/`cache:rebuild`. No rsync of application code anywhere. |
| Application rsync removed | **Accepted** | `deploy/push-and-deploy.sh` (the `rsync --delete` script) is a refuse-to-run stub; no active `rsync` remains in `deploy/`. |
| `mel_preflight` (path + document root + identity) | **Accepted** | `deploy/lib/mel-deploy-guards.sh::mel_preflight` — allowlist path, git+composer app dir, `<path>/web` docroot with `index.php`, `.mel-application` marker match. Runs before any change. |
| Path validation / forbidden parents | **Accepted** | `mel_guard_target_path` — 3-entry allowlist, refuses `/`, `/home`, `/home/mel`, `/home/mel/sites`, `/home/mel/shared`, `/home/mel/staging`, traversal. Unit-tested 17/17. |
| Rollback preflight + path + application validation | **Accepted** | `rollback-*.sh` call `mel_preflight`; `mel_rollback_restore` requires the backup under this app's own namespace, rejects the namespace root, and requires a real artefact. |
| Fail-closed backup before changes | **Accepted** | `mel_backup_create` returns non-zero on any failure; deploy aborts before `git pull`. |
| No secrets committed | **Accepted** | `.gitignore` excludes `settings.local.php`, `settings.production.php`, `.env*`, files/, `.mel-application`. |
| No runtime git in Drupal | **Accepted** | Deployed commit is read from `$settings['mel.deploy.commit']` (env), never a runtime `git` call. |

Because these already hold, they are **documented, not duplicated** — satisfying the STOP conditions ("Git deployment is already canonical", "rollback verified").

## 2. Gaps this phase closes

| Gap | Added |
|---|---|
| No deployment journal | `mel_write_journal` + `mel_next_build_number` → one JSON per deploy under `/home/mel/shared/deployments/YYYY-MM-DD-HHMMSS.json`. |
| No structured verification | `mel_verify` (13 read-only checks) + `deploy/verify-deployment.sh` CLI, returning PASS / WARNING / FAIL. |
| Thin end-of-deploy summary | `mel_finalize_deploy` prints a full report (app, env, branch, commit, build, Drupal/PHP/Composer versions, duration, verification result, journal path). |
| Health checks duplicated inline | Replaced the ad-hoc bootstrap/maintenance checks with the shared `mel_verify` — single source of truth for deploy **and** the standalone verifier. |

## 3. rsync usage — final determination

The only `rsync` reference remaining is the retired `push-and-deploy.sh` stub's
comment. **No script performs rsync.** Application code deploys purely by git.
No runtime-asset rsync is required today (files/ live inside each app dir and are
gitignored, so `git pull` never touches them). If a runtime-asset sync is ever
needed it must target only `web/sites/default/files/`, never application code,
and never use `--delete` against an app directory (documented in
`docs/deployment/architecture.md`).

## 4. Non-negotiables — compliance

Drupal 11 safe · Composer safe (`--no-dev --optimize-autoloader`) · cPanel safe
(pull-based, no root, scoped to app dir) · security-first (allowlist + marker +
no secrets) · no duplicate logic (one `mel_verify`) · no runtime git in Drupal ·
no filesystem change before preflight · code + docs only (nothing deployed).

## 5. Assumptions still requiring the manual runbook

Unchanged from the previous audit: the `/home/mel/sites/*` + `/home/mel/shared/*`
layout, `.mel-application` markers, and docroot symlinks must be created by an
operator (`docs/deployment/server-layout.md`). The scripts **fail closed** until
then, so shipping them is safe.
