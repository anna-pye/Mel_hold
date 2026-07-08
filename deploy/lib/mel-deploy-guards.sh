#!/usr/bin/env bash
#
# mel-deploy-guards.sh — pure, fail-closed helpers for the Hold deploy scripts
# (deploy-hold.sh / rollback-hold.sh / verify-deployment.sh) in THIS repository.
#
# SCOPE: Mel_hold deploys ONLY the Hold application. Staging and production
# MyEventLane are owned by a SEPARATE repository —
# https://github.com/anna-pye/mel-deployment — and are explicitly forbidden
# here. See docs/deployment/repository-ownership.md.
#
# This library NEVER chooses a deployment target. It only *validates* a target
# a caller has already hardcoded, and provides backup / rollback / verify /
# journal / confirmation helpers. It can only ever refuse or narrow — never
# broaden — a deployment, so sourcing it cannot reintroduce a generic target.
#
# Source it; do not execute it directly. Callers must run `set -euo pipefail`.

# --- The ONLY path any deployment in THIS repo may target. Hold, hardcoded. ---
# Keep in exact sync with docs/deployment/server-layout.md.
MEL_ALLOWED_HOLD="/home/mel/sites/myeventlane_hold"

# Paths that must ALWAYS be rejected outright: parents, shared roots, and the
# staging/production applications (owned by the mel-deployment repository — a
# Mel_hold script must never touch them).
MEL_FORBIDDEN_PATHS="/ /home /home/mel /home/mel/ /home/mel/sites /home/mel/shared /home/mel/staging /home/mel/sites/myeventlane_staging /home/mel/sites/myeventlane_production"

mel_die() {
  echo "ERROR: $*" >&2
  exit 2
}

mel_info() { echo "==> $*"; }
mel_warn() { echo "WARNING: $*" >&2; }

# Normalise a path: strip a single trailing slash (but keep "/").
mel_normalize_path() {
  local p="${1:-}"
  if [[ "${p}" == "/" ]]; then
    printf '%s' "/"
  else
    printf '%s' "${p%/}"
  fi
}

# Fail unless $1 is EXACTLY the Hold application directory.
# Rejects every parent, sibling, shared root, the staging/production apps, and
# any other path.
mel_guard_target_path() {
  local raw="${1:-}"
  [[ -n "${raw}" ]] || mel_die "mel_guard_target_path requires a path."

  # Reject anything that is not absolute or contains traversal.
  [[ "${raw}" == /* ]] || mel_die "Deployment path must be absolute: ${raw}"
  case "${raw}" in
    *..*) mel_die "Deployment path must not contain '..': ${raw}" ;;
  esac

  local path
  path="$(mel_normalize_path "${raw}")"

  # Explicit forbidden refusal (clear error before the allowlist). Staging and
  # production paths land here — they are owned by the mel-deployment repo.
  local forbidden
  for forbidden in ${MEL_FORBIDDEN_PATHS}; do
    if [[ "${path}" == "$(mel_normalize_path "${forbidden}")" ]]; then
      case "${path}" in
        */myeventlane_staging|*/myeventlane_production)
          mel_die "Refusing: ${path} is a MyEventLane staging/production app.
It is deployed from https://github.com/anna-pye/mel-deployment, NOT from Mel_hold." ;;
        *)
          mel_die "Refusing to deploy to forbidden path: ${path}" ;;
      esac
    fi
  done

  # Allowlist: Hold, and only Hold.
  case "${path}" in
    "${MEL_ALLOWED_HOLD}")
      return 0
      ;;
  esac

  mel_die "Deployment path is not allowlisted: ${path}
This repository (Mel_hold) deploys ONLY the Hold application:
  ${MEL_ALLOWED_HOLD}
Staging/production MyEventLane are owned by github.com/anna-pye/mel-deployment."
}

# Fail unless the current working directory IS the application directory.
# The operator runbook requires `cd <app path>` before running a deploy, so a
# mismatched CWD is a strong signal the wrong script/app is being run.
mel_require_cwd() {
  local path="${1:?}" here there
  here="$(pwd -P)"
  there="$(cd "${path}" 2>/dev/null && pwd -P || echo '')"
  [[ -n "${there}" && "${here}" == "${there}" ]] \
    || mel_die "Run this from ${path} (cd there first). Current directory: ${here}"
}

# Map an allowlisted deployment path to its EXPECTED application name.
# The expected identity is derived from the trusted path, never from the
# on-disk marker — so callers can compare the marker against this and catch a
# wrong marker instead of rubber-stamping it.
mel_expected_app_for_path() {
  local path; path="$(mel_normalize_path "${1:?}")"
  case "${path}" in
    "${MEL_ALLOWED_HOLD}") printf '%s' "myeventlane_hold" ;;
    *) mel_die "No expected application is defined for ${path} in this repository." ;;
  esac
}

# Fail unless $1 is a Composer project root that is a git checkout.
mel_require_app_dir() {
  local path="${1:-}"
  [[ -d "${path}" ]] || mel_die "Deployment path does not exist: ${path}
Create and initialise it first — see docs/deployment/server-layout.md."
  [[ -f "${path}/composer.json" ]] || mel_die "No composer.json in ${path} (not a Drupal app root)."
  [[ -d "${path}/.git" ]] || mel_die "No .git in ${path} (git-based deploy requires a clone)."
}

# Preflight: verify path, document root, and application identity BEFORE any
# filesystem change. Fail-closed on anything unexpected.
# Args: app_name, deploy_path, [web_root=deploy_path/web].
mel_preflight() {
  local app="${1:?}" path="${2:?}" web_root="${3:-${2}/web}"

  # 1. Deployment path — allowlisted, and a real git/Composer app dir.
  mel_guard_target_path "${path}"
  mel_require_app_dir "${path}"

  # 2. Document root — must be exactly <path>/web and a real Drupal docroot.
  [[ "${web_root}" == "${path}/web" ]] \
    || mel_die "Web root '${web_root}' is not '${path}/web'. Refusing."
  [[ -d "${web_root}" ]] || mel_die "Web root does not exist: ${web_root}"
  [[ -f "${web_root}/index.php" ]] \
    || mel_die "Web root is not a Drupal docroot (no index.php): ${web_root}"

  # 3. Expected application — an out-of-band identity marker. All three
  #    environments are clones of the SAME repository, so git/composer cannot
  #    tell them apart; the marker is the only reliable proof that the checkout
  #    at this path is the intended application. Created during migration
  #    (docs/deployment/server-layout.md); gitignored so it never commits and
  #    never dirties the working tree.
  local marker="${path}/.mel-application"
  [[ -f "${marker}" ]] || mel_die "Missing application identity marker: ${marker}
Create it once during migration:  echo '${app}' > ${marker}
See docs/deployment/server-layout.md."
  local id
  id="$(tr -d '[:space:]' < "${marker}")"
  [[ "${id}" == "${app}" ]] \
    || mel_die "Identity mismatch at ${path}: marker says '${id}', expected '${app}'. Refusing."

  mel_info "Preflight OK: ${app} @ ${path} (web root ${web_root})"
}

# Validate git state. Args: path, expected_branch, expected_remote_substring.
# Sets globals: MEL_GIT_LOCAL, MEL_GIT_REMOTE, MEL_GIT_BRANCH.
mel_validate_git() {
  local path="${1:?}" expected_branch="${2:?}" expected_remote="${3:?}"
  local branch remote_url

  branch="$(git -C "${path}" rev-parse --abbrev-ref HEAD)"
  [[ "${branch}" == "${expected_branch}" ]] \
    || mel_die "Checked-out branch is '${branch}', expected '${expected_branch}'. Aborting."

  remote_url="$(git -C "${path}" remote get-url origin 2>/dev/null || echo '')"
  [[ "${remote_url}" == *"${expected_remote}"* ]] \
    || mel_die "origin remote '${remote_url}' does not match expected '${expected_remote}'. Aborting."

  # Working tree must be clean — never deploy over local edits.
  if [[ -n "$(git -C "${path}" status --porcelain)" ]]; then
    mel_die "Working tree at ${path} is not clean. Commit/stash or investigate before deploying."
  fi

  git -C "${path}" fetch --quiet origin "${expected_branch}"
  MEL_GIT_BRANCH="${branch}"
  MEL_GIT_LOCAL="$(git -C "${path}" rev-parse HEAD)"
  MEL_GIT_REMOTE="$(git -C "${path}" rev-parse "origin/${expected_branch}")"
}

# Build a drush command array for an app path (echoes nothing; use $MEL_DRUSH).
mel_set_drush() {
  local path="${1:?}"
  MEL_DRUSH=(php -d memory_limit=512M "${path}/vendor/bin/drush")
}

# Pre-deploy backup: code archive + database dump + commit marker.
# Args: app_name, app_path, backup_root. Echoes the backup directory.
mel_backup_create() {
  local app="${1:?}" path="${2:?}" backup_root="${3:?}"
  local stamp dir
  stamp="$(date +%Y%m%d-%H%M%S)"
  dir="${backup_root}/${app}/${stamp}"
  mkdir -p "${dir}" || { mel_warn "Cannot create backup directory ${dir}."; return 1; }

  # Record the exact commit we are deploying FROM (required for git rollback).
  git -C "${path}" rev-parse HEAD > "${dir}/PREVIOUS_COMMIT" 2>/dev/null \
    || { mel_warn "Could not record current commit for rollback."; return 1; }

  # Lightweight code archive (excludes vendor, uploaded files, git history).
  tar -czf "${dir}/code.tgz" \
    --exclude='./vendor' \
    --exclude='./web/sites/default/files' \
    --exclude='./.git' \
    -C "${path}" . 2>/dev/null \
    || { mel_warn "Code archive failed — refusing to deploy without a backup."; return 1; }

  # Database dump via the app's own drush (uses the site's own credentials).
  # A backup is the whole point, so this is fail-closed: if the site bootstraps
  # the dump MUST succeed; if it cannot bootstrap (e.g. a first-ever deploy with
  # no database yet), require an explicit MEL_ALLOW_NO_BACKUP=1 acknowledgement.
  mel_set_drush "${path}"
  if "${MEL_DRUSH[@]}" status 2>/dev/null | grep -q 'Successful'; then
    "${MEL_DRUSH[@]}" sql:dump --gzip --result-file="${dir}/database.sql" >/dev/null 2>&1 \
      || { mel_warn "Database dump failed though the site bootstrapped."; return 1; }
  elif [[ "${MEL_ALLOW_NO_BACKUP:-0}" == "1" ]]; then
    mel_warn "No database backup taken (site did not bootstrap); proceeding due to MEL_ALLOW_NO_BACKUP=1."
  else
    mel_warn "Cannot back up the database — the site did not bootstrap.
Set MEL_ALLOW_NO_BACKUP=1 to proceed without a DB backup (intended for a first deploy only)."
    return 1
  fi

  printf '%s' "${dir}"
}

# Print a deployment summary. Args: env, app, path, web_root, branch, local, remote, db.
mel_print_summary() {
  cat <<SUMMARY

──────────────────────────────────────────────────────────────
  Deployment summary
──────────────────────────────────────────────────────────────
  Environment   : ${1}
  Application   : ${2}
  Deploy path   : ${3}
  Web root      : ${4}
  Branch        : ${5}
  Current commit: ${6}
  Remote commit : ${7}
  Database      : ${8}
──────────────────────────────────────────────────────────────
SUMMARY
}

# Interactive confirmation. Args: env_label, auto_yes(0/1).
mel_confirm() {
  local env_label="${1:?}" auto_yes="${2:-0}"
  if [[ "${auto_yes}" == "1" ]]; then
    mel_info "Confirmation skipped (--yes)."
    return 0
  fi
  local answer
  read -r -p "Type the environment name '${env_label}' to proceed: " answer
  [[ "${answer}" == "${env_label}" ]] || mel_die "Confirmation did not match. Aborting."
}

# Roll back an app to a previous commit + database dump.
# Args: app_path, backup_dir, expected_namespace (= BACKUP_ROOT/APP_NAME).
mel_rollback_restore() {
  local path="${1:?}" backup_dir expected_ns
  backup_dir="$(mel_normalize_path "${2:?}")"
  expected_ns="$(mel_normalize_path "${3:?}")"

  [[ -d "${backup_dir}" ]] || mel_die "Backup directory not found: ${backup_dir}"

  # The backup MUST belong to this application's own namespace — never restore
  # another app's database/commit into this environment.
  [[ "${backup_dir}" != "${expected_ns}" ]] \
    || mel_die "Refusing: '${backup_dir}' is the namespace root, not a specific backup."
  case "${backup_dir}/" in
    "${expected_ns}/"*) : ;;
    *) mel_die "Refusing: backup '${backup_dir}' is not under this app's namespace '${expected_ns}'." ;;
  esac

  # It must actually be a backup (have a restorable artefact).
  [[ -f "${backup_dir}/PREVIOUS_COMMIT" || -f "${backup_dir}/database.sql.gz" ]] \
    || mel_die "Not a valid backup (no PREVIOUS_COMMIT or database.sql.gz): ${backup_dir}"

  if [[ -f "${backup_dir}/PREVIOUS_COMMIT" ]]; then
    local prev
    prev="$(cat "${backup_dir}/PREVIOUS_COMMIT")"
    mel_info "Restoring code to ${prev}"
    git -C "${path}" reset --hard "${prev}"
  else
    mel_warn "No PREVIOUS_COMMIT marker; skipping git reset."
  fi

  ( cd "${path}" && composer install --no-dev --optimize-autoloader --no-interaction )

  if [[ -f "${backup_dir}/database.sql.gz" ]]; then
    mel_set_drush "${path}"
    mel_info "Restoring database from ${backup_dir}/database.sql.gz"
    gunzip -c "${backup_dir}/database.sql.gz" | "${MEL_DRUSH[@]}" sql:cli
    "${MEL_DRUSH[@]}" cr
  else
    mel_warn "No database dump in backup; database left unchanged."
  fi

  mel_info "Rollback complete."
}

# ============================================================================
# Verification, versions, journal, and final summary.
#
# Single source of truth for post-deploy verification: both the deploy scripts
# (via mel_finalize_deploy) and the standalone deploy/verify-deployment.sh call
# mel_verify, so there is no duplicated verification logic.
# ============================================================================

# Collect tool/runtime versions. Sets MEL_VER_{DRUPAL,PHP,COMPOSER}.
mel_collect_versions() {
  local path="${1:?}"
  mel_set_drush "${path}"
  MEL_VER_DRUPAL="$("${MEL_DRUSH[@]}" status --field=drupal-version 2>/dev/null || echo 'unknown')"
  MEL_VER_PHP="$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 'unknown')"
  MEL_VER_COMPOSER="$(composer --version 2>/dev/null | awk '{print $3}' || echo 'unknown')"
  [[ -n "${MEL_VER_DRUPAL}" ]] || MEL_VER_DRUPAL="unknown"
  [[ -n "${MEL_VER_PHP}" ]] || MEL_VER_PHP="unknown"
  [[ -n "${MEL_VER_COMPOSER}" ]] || MEL_VER_COMPOSER="unknown"
}

# Read-only health verification. Args: app, path, [expected_branch=main].
# Sets MEL_VERIFY_RESULT (PASS|WARNING|FAIL) and MEL_VERIFY_LINES[] ("ST|label").
# Never mutates anything; every check is defensive so one failure cannot abort.
mel_verify() {
  local app="${1:?}" path="${2:?}" expected_branch="${3:-main}"
  # Normalise once so a trailing slash (e.g. from a CLI arg) never causes a
  # false mismatch in the path-based checks below.
  path="$(mel_normalize_path "${path}")"
  MEL_VERIFY_LINES=()
  MEL_VERIFY_FAILS=0
  MEL_VERIFY_WARNS=0
  _add() {
    MEL_VERIFY_LINES+=("$1|$2")
    if [[ "$1" == "FAIL" ]]; then MEL_VERIFY_FAILS=$((MEL_VERIFY_FAILS + 1)); fi
    if [[ "$1" == "WARN" ]]; then MEL_VERIFY_WARNS=$((MEL_VERIFY_WARNS + 1)); fi
  }
  mel_set_drush "${path}"
  local D=("${MEL_DRUSH[@]}")

  # Correct application (identity marker).
  local id=""
  [[ -f "${path}/.mel-application" ]] && id="$(tr -d '[:space:]' < "${path}/.mel-application")"
  if [[ "${id}" == "${app}" ]]; then _add PASS "Correct application (${app})"; else _add FAIL "Application marker mismatch (got '${id:-none}')"; fi

  # Correct document root.
  if [[ -d "${path}/web" && -f "${path}/web/index.php" ]]; then _add PASS "Document root is ${path}/web"; else _add FAIL "Document root missing (${path}/web/index.php)"; fi

  # Git clean working tree.
  if [[ -z "$(git -C "${path}" status --porcelain 2>/dev/null)" ]]; then _add PASS "Git working tree clean"; else _add WARN "Git working tree not clean"; fi

  # Correct branch (the deploy's expected branch, not a hardcoded 'main').
  local br; br="$(git -C "${path}" rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')"
  if [[ "${br}" == "${expected_branch}" ]]; then _add PASS "On branch ${expected_branch}"; else _add FAIL "On branch '${br}', expected ${expected_branch}"; fi

  # Expected commit (HEAD matches origin/<expected_branch>; no network fetch here).
  local head origin
  head="$(git -C "${path}" rev-parse HEAD 2>/dev/null || echo '')"
  origin="$(git -C "${path}" rev-parse "origin/${expected_branch}" 2>/dev/null || echo '')"
  if [[ -n "${origin}" && "${head}" == "${origin}" ]]; then _add PASS "At origin/${expected_branch} (${head:0:12})";
  elif [[ -n "${origin}" ]]; then _add WARN "HEAD differs from origin/${expected_branch} (${head:0:12})";
  else _add WARN "origin/${expected_branch} ref unavailable"; fi

  # Composer installed.
  if [[ -f "${path}/vendor/autoload.php" ]]; then _add PASS "Composer dependencies installed"; else _add FAIL "vendor/autoload.php missing"; fi

  # Drupal bootstraps.
  if "${D[@]}" status 2>/dev/null | grep -q 'Successful'; then _add PASS "Drupal bootstraps"; else _add FAIL "Drupal does not bootstrap"; fi

  # Database reachable.
  local dbs; dbs="$("${D[@]}" status --field=db-status 2>/dev/null || echo '')"
  if [[ "${dbs}" == *Connected* ]]; then _add PASS "Database reachable"; else _add FAIL "Database not reachable"; fi

  # Maintenance mode off.
  local maint; maint="$("${D[@]}" state:get system.maintenance_mode --format=string 2>/dev/null || echo 0)"
  if [[ "${maint}" != "1" ]]; then _add PASS "Maintenance mode off"; else _add FAIL "Site in maintenance mode"; fi

  # Config clean. Use machine-readable JSON (an empty changelist == in sync) so
  # the check does not depend on where Drush prints its "No differences" notice
  # (Drush 13 emits it on stderr, which a stdout grep would miss). Distinguish a
  # genuine empty result from a Drush *failure* — the `if` runs the command so
  # its exit status is honoured (and it is set -e safe) rather than masking a
  # non-zero exit as an empty list.
  local cfg
  if cfg="$("${D[@]}" config:status --format=json 2>/dev/null)"; then
    cfg="$(printf '%s' "${cfg}" | tr -d '[:space:]')"
    if [[ -z "${cfg}" || "${cfg}" == "[]" || "${cfg}" == "{}" ]]; then _add PASS "Configuration in sync"; else _add WARN "Configuration drift detected"; fi
  else
    _add WARN "Configuration status unavailable (drush error)"
  fi

  # No pending database updates. Same JSON approach; a Drush failure is reported
  # rather than silently treated as "none pending".
  local upd
  if upd="$("${D[@]}" updatedb:status --format=json 2>/dev/null)"; then
    upd="$(printf '%s' "${upd}" | tr -d '[:space:]')"
    if [[ -z "${upd}" || "${upd}" == "[]" || "${upd}" == "{}" ]]; then _add PASS "No pending database updates"; else _add WARN "Pending database updates"; fi
  else
    _add WARN "Update status unavailable (drush error)"
  fi

  # Cron available (has run at least once).
  local cron; cron="$("${D[@]}" state:get system.cron_last --format=string 2>/dev/null || echo '')"
  if [[ -n "${cron}" ]]; then _add PASS "Cron available"; else _add WARN "Cron has never run"; fi

  # Watchdog readable.
  if "${D[@]}" watchdog:show --count=1 >/dev/null 2>&1; then _add PASS "Watchdog readable"; else _add WARN "Watchdog not readable (dblog disabled?)"; fi

  if [[ ${MEL_VERIFY_FAILS} -gt 0 ]]; then MEL_VERIFY_RESULT="FAIL";
  elif [[ ${MEL_VERIFY_WARNS} -gt 0 ]]; then MEL_VERIFY_RESULT="WARNING";
  else MEL_VERIFY_RESULT="PASS"; fi
}

# Print the collected verification lines.
mel_print_verify() {
  echo "Verification:"
  if [[ ${#MEL_VERIFY_LINES[@]} -eq 0 ]]; then echo "  (no checks run)"; return 0; fi
  local line st label
  for line in "${MEL_VERIFY_LINES[@]}"; do
    st="${line%%|*}"; label="${line#*|}"
    echo "  [${st}] ${label}"
  done
}

# Monotonic per-application build number stored under the journal root.
mel_next_build_number() {
  local app="${1:?}" deployments_root="${2:?}"
  local counter="${deployments_root}/.build-${app}" n=0
  mkdir -p "${deployments_root}" 2>/dev/null || true
  [[ -f "${counter}" ]] && n="$(cat "${counter}" 2>/dev/null || echo 0)"
  [[ "${n}" =~ ^[0-9]+$ ]] || n=0
  n=$((n + 1))
  echo "${n}" > "${counter}" 2>/dev/null || true
  printf '%s' "${n}"
}

# Write one deployment journal entry (JSON). Contains NO secrets. Echoes path.
# Args: app env branch commit build path web_root dtype preflight result backup_dir deployments_root
mel_write_journal() {
  local app="${1:?}" env="${2:?}" branch="${3:?}" commit="${4:?}" build="${5:?}" \
        path="${6:?}" web_root="${7:?}" dtype="${8:?}" preflight="${9:?}" result="${10:?}" \
        backup_dir="${11:-}" deployments_root="${12:?}"
  mkdir -p "${deployments_root}" 2>/dev/null || return 1
  local ts host user rollback file
  ts="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  host="$(hostname 2>/dev/null || echo unknown)"
  user="$(id -un 2>/dev/null || echo unknown)"
  if [[ -n "${backup_dir}" && -d "${backup_dir}" ]]; then rollback="true"; else rollback="false"; fi
  file="${deployments_root}/$(date +%Y-%m-%d-%H%M%S).json"
  # Signal a genuine write failure so callers can warn (the trailing printf
  # must not mask a failed cat).
  if ! cat > "${file}" 2>/dev/null <<JSON
{
  "application": "${app}",
  "environment": "${env}",
  "branch": "${branch}",
  "commit": "${commit}",
  "build": ${build},
  "timestamp": "${ts}",
  "hostname": "${host}",
  "deploy_user": "${user}",
  "deployment_type": "${dtype}",
  "web_root": "${web_root}",
  "preflight_result": "${preflight}",
  "deployment_result": "${result}",
  "rollback_available": ${rollback},
  "composer_version": "${MEL_VER_COMPOSER:-unknown}",
  "drupal_version": "${MEL_VER_DRUPAL:-unknown}",
  "php_version": "${MEL_VER_PHP:-unknown}"
}
JSON
  then
    return 1
  fi
  printf '%s' "${file}"
}

# Orchestrate post-deploy: versions -> verify -> journal -> summary.
# Returns non-zero only when verification FAILs. Args:
#   app env path web_root branch backup_dir start_epoch deployments_root
mel_finalize_deploy() {
  local app="${1:?}" env="${2:?}" path="${3:?}" web_root="${4:?}" branch="${5:?}" \
        backup_dir="${6:-}" start_epoch="${7:?}" deployments_root="${8:?}"
  local commit build duration journal
  commit="$(git -C "${path}" rev-parse HEAD 2>/dev/null || echo 'unknown')"

  mel_collect_versions "${path}"
  mel_verify "${app}" "${path}" "${branch}"
  mel_print_verify

  duration=$(( $(date +%s) - start_epoch ))
  # Journal/build are records of a deploy that already happened — a failure to
  # write them must NOT be reported as (or conflated with) a verification
  # failure. Guard them so this function's exit code reflects verification only.
  build="$(mel_next_build_number "${app}" "${deployments_root}" 2>/dev/null || echo 0)"
  if ! journal="$(mel_write_journal "${app}" "${env}" "${branch}" "${commit}" "${build}" \
      "${path}" "${web_root}" "git" "PASS" "${MEL_VERIFY_RESULT}" "${backup_dir}" "${deployments_root}" 2>/dev/null)"; then
    mel_warn "Deployment journal could not be written (the deploy itself is unaffected)."
    journal="(journal write failed)"
  fi

  cat <<SUMMARY

──────────────────────────────────────────────────────────────
  Deployment report
──────────────────────────────────────────────────────────────
  Application    : ${app}
  Environment    : ${env}
  Branch         : ${branch}
  Commit         : ${commit}
  Build          : ${build}
  Drupal version : ${MEL_VER_DRUPAL}
  PHP version    : ${MEL_VER_PHP}
  Composer       : ${MEL_VER_COMPOSER}
  Duration       : ${duration}s
  Verification   : ${MEL_VERIFY_RESULT}
  Journal        : ${journal}
  Rollback       : ${backup_dir:-none}
──────────────────────────────────────────────────────────────
SUMMARY

  [[ "${MEL_VERIFY_RESULT}" != "FAIL" ]] || return 1
  return 0
}
