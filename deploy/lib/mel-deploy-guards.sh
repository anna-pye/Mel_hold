#!/usr/bin/env bash
#
# mel-deploy-guards.sh — pure, fail-closed helpers for the per-application
# deployment scripts (deploy-hold.sh / deploy-staging.sh / deploy-production.sh).
#
# This library NEVER chooses a deployment target. It only *validates* a target
# a caller has already hardcoded, and provides backup / rollback / summary /
# confirmation helpers. It can only ever refuse or narrow — never broaden — a
# deployment, so sourcing it cannot reintroduce a generic target.
#
# Source it; do not execute it directly. Callers must run `set -euo pipefail`.

# --- The ONLY paths any deployment may ever target. Hardcoded allowlist. -----
# Keep in exact sync with docs/deployment/server-layout.md.
MEL_ALLOWED_HOLD="/home/mel/sites/myeventlane_hold"
MEL_ALLOWED_STAGING="/home/mel/sites/myeventlane_staging"
MEL_ALLOWED_PRODUCTION="/home/mel/sites/myeventlane_production"

# Paths that must ALWAYS be rejected outright (parents / shared roots).
MEL_FORBIDDEN_PATHS="/ /home /home/mel /home/mel/ /home/mel/sites /home/mel/shared /home/mel/staging"

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

# Fail unless $1 is EXACTLY one of the three allowlisted application dirs.
# Rejects every parent, sibling, shared root, and any un-allowlisted path.
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

  # Explicit forbidden-parent refusal (clear error before the allowlist).
  local forbidden
  for forbidden in ${MEL_FORBIDDEN_PATHS}; do
    if [[ "${path}" == "$(mel_normalize_path "${forbidden}")" ]]; then
      mel_die "Refusing to deploy to forbidden path: ${path}"
    fi
  done

  # Allowlist: must match one of exactly three application directories.
  case "${path}" in
    "${MEL_ALLOWED_HOLD}"|"${MEL_ALLOWED_STAGING}"|"${MEL_ALLOWED_PRODUCTION}")
      return 0
      ;;
  esac

  mel_die "Deployment path is not allowlisted: ${path}
Allowed targets are exactly:
  ${MEL_ALLOWED_HOLD}
  ${MEL_ALLOWED_STAGING}
  ${MEL_ALLOWED_PRODUCTION}"
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
  mkdir -p "${dir}"

  # Record the exact commit we are deploying FROM (for git rollback).
  git -C "${path}" rev-parse HEAD > "${dir}/PREVIOUS_COMMIT" 2>/dev/null || true

  # Lightweight code archive (excludes vendor, uploaded files, git history).
  tar -czf "${dir}/code.tgz" \
    --exclude='./vendor' \
    --exclude='./web/sites/default/files' \
    --exclude='./.git' \
    -C "${path}" . 2>/dev/null || mel_warn "Code archive incomplete."

  # Database dump via the app's own drush (uses the site's own credentials).
  if [[ -x "${path}/vendor/bin/drush" ]]; then
    mel_set_drush "${path}"
    if "${MEL_DRUSH[@]}" status 2>/dev/null | grep -q 'Successful'; then
      "${MEL_DRUSH[@]}" sql:dump --gzip --result-file="${dir}/database.sql" \
        >/dev/null 2>&1 || mel_warn "Database dump failed (site may not bootstrap)."
    else
      mel_warn "Skipped DB dump — drush could not bootstrap the site."
    fi
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
# Args: app_path, backup_dir.
mel_rollback_restore() {
  local path="${1:?}" backup_dir="${2:?}"
  [[ -d "${backup_dir}" ]] || mel_die "Backup directory not found: ${backup_dir}"

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
