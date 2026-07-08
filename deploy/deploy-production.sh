#!/usr/bin/env bash
#
# deploy-production.sh — deploy ONLY the future MEL Production application.
#
# Hardcoded target. Cannot deploy Hold or Staging. No generic path, no
# rsync --delete. Production is the strictest: --yes cannot skip confirmation,
# and an explicit MEL_ALLOW_PRODUCTION_DEPLOY=1 env acknowledgement is required.
#
#   MEL_ALLOW_PRODUCTION_DEPLOY=1 \
#     bash /home/mel/sites/myeventlane_production/deploy/deploy-production.sh [--dry-run]
#
set -euo pipefail

# --- Hardcoded identity for THIS application only. ---------------------------
readonly APP_NAME="myeventlane_production"
readonly ENV_LABEL="production"
readonly DEPLOY_PATH="/home/mel/sites/myeventlane_production"
readonly WEB_ROOT="/home/mel/sites/myeventlane_production/web"
# Production deploys the reviewed release branch. Change deliberately.
readonly BRANCH="main"
readonly REMOTE_MATCH="Mel_hold"
readonly BACKUP_ROOT="/home/mel/shared/backups"
readonly LOG_ROOT="/home/mel/shared/logs"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/mel-deploy-guards.sh
source "${SCRIPT_DIR}/lib/mel-deploy-guards.sh"

DRY_RUN=0
for arg in "$@"; do
  case "${arg}" in
    --dry-run) DRY_RUN=1 ;;
    --yes) mel_die "--yes is not permitted for production. Confirmation is mandatory." ;;
    *) mel_die "Unknown argument: ${arg} (accepts --dry-run)" ;;
  esac
done

# Explicit environment acknowledgement — a second, deliberate gate.
if [[ "${DRY_RUN}" != "1" && "${MEL_ALLOW_PRODUCTION_DEPLOY:-0}" != "1" ]]; then
  mel_die "Refusing production deploy without MEL_ALLOW_PRODUCTION_DEPLOY=1."
fi

# --- Preflight + safety guards (fail closed, before any change). --------------
mel_preflight "${APP_NAME}" "${DEPLOY_PATH}" "${WEB_ROOT}"
mel_validate_git "${DEPLOY_PATH}" "${BRANCH}" "${REMOTE_MATCH}"

mel_set_drush "${DEPLOY_PATH}"
DB_NAME="$("${MEL_DRUSH[@]}" status --field=db-name 2>/dev/null || echo 'unknown')"

mel_print_summary "${ENV_LABEL}" "${APP_NAME}" "${DEPLOY_PATH}" "${WEB_ROOT}" \
  "${BRANCH}" "${MEL_GIT_LOCAL}" "${MEL_GIT_REMOTE}" "${DB_NAME}"

if [[ "${DRY_RUN}" == "1" ]]; then
  mel_info "DRY RUN — no changes made. Would fast-forward to ${MEL_GIT_REMOTE}, back up, then composer/updb/cim/cr."
  exit 0
fi

# Production always prompts (auto_yes forced to 0).
mel_confirm "${ENV_LABEL}" "0"

# --- Backup BEFORE any change. -----------------------------------------------
mel_info "Creating pre-deploy backup…"
if ! BACKUP_DIR="$(mel_backup_create "${APP_NAME}" "${DEPLOY_PATH}" "${BACKUP_ROOT}")"; then
  mel_die "Pre-deploy backup failed — aborting before any change was made."
fi
mel_info "Backup: ${BACKUP_DIR}"

trap 'echo "" >&2; echo "DEPLOY FAILED. Roll back with:" >&2; echo "  bash ${SCRIPT_DIR}/rollback-production.sh ${BACKUP_DIR}" >&2' ERR

mkdir -p "${LOG_ROOT}/${APP_NAME}"
LOG_FILE="${LOG_ROOT}/${APP_NAME}/deploy-$(date +%Y%m%d-%H%M%S).log"
echo "deploy ${APP_NAME} from ${MEL_GIT_LOCAL} to ${MEL_GIT_REMOTE} at $(date -u +%FT%TZ)" >> "${LOG_FILE}"

# --- Deploy (git-based, in place; no rsync). ---------------------------------
cd "${DEPLOY_PATH}"
mel_info "Fast-forwarding ${BRANCH}…"
git checkout "${BRANCH}"
git pull --ff-only origin "${BRANCH}"

mel_info "Installing Composer dependencies (production)…"
composer install --no-dev --optimize-autoloader --no-interaction

mel_info "Running database updates…"
"${MEL_DRUSH[@]}" updatedb -y

mel_info "Importing configuration…"
"${MEL_DRUSH[@]}" config:import -y

mel_info "Rebuilding caches…"
"${MEL_DRUSH[@]}" cache:rebuild

# --- Health checks. ----------------------------------------------------------
mel_info "Verifying site health…"
"${MEL_DRUSH[@]}" status | grep -q 'Successful' || mel_die "Drupal did not bootstrap after deploy."
maint="$("${MEL_DRUSH[@]}" state:get system.maintenance_mode --format=string 2>/dev/null || echo 0)"
[[ "${maint}" != "1" ]] || mel_die "Site left in maintenance mode."

echo "success ${APP_NAME} now at $(git rev-parse HEAD) at $(date -u +%FT%TZ)" >> "${LOG_FILE}"
trap - ERR

cat <<DONE

✅ Production deploy complete.
   Now at : $(git rev-parse HEAD)
   Backup : ${BACKUP_DIR}
   Log    : ${LOG_FILE}
   Rollback (if needed): bash ${SCRIPT_DIR}/rollback-production.sh ${BACKUP_DIR}
DONE
