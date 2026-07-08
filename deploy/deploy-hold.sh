#!/usr/bin/env bash
#
# deploy-hold.sh — deploy ONLY the MEL Hold application.
#
# Mel_hold deploys ONLY Hold. Staging/production MyEventLane are owned by the
# separate github.com/anna-pye/mel-deployment repository and cannot be deployed
# from here. This script hardcodes its target and refuses to run unless the
# current directory, deployment path, web root, and .mel-application marker all
# match Hold. There is no generic path variable and no rsync --delete.
#
# Operator command (run ON THE SERVER, as the deploy user):
#
#   cd /home/mel/sites/myeventlane_hold
#   ./deploy/deploy-hold.sh              [--dry-run] [--yes]
#
set -euo pipefail

# --- Hardcoded identity for THIS application only. ---------------------------
readonly APP_NAME="myeventlane_hold"
readonly ENV_LABEL="hold"
readonly DEPLOY_PATH="/home/mel/sites/myeventlane_hold"
readonly WEB_ROOT="/home/mel/sites/myeventlane_hold/web"
readonly BRANCH="main"
readonly REMOTE_MATCH="Mel_hold"
readonly BACKUP_ROOT="/home/mel/shared/backups"
readonly LOG_ROOT="/home/mel/shared/logs"
readonly DEPLOYMENTS_ROOT="/home/mel/shared/deployments"
readonly START_EPOCH="$(date +%s)"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/mel-deploy-guards.sh
source "${SCRIPT_DIR}/lib/mel-deploy-guards.sh"

DRY_RUN=0
AUTO_YES=0
for arg in "$@"; do
  case "${arg}" in
    --dry-run) DRY_RUN=1 ;;
    --yes) AUTO_YES=1 ;;
    *) mel_die "Unknown argument: ${arg} (accepts --dry-run, --yes)" ;;
  esac
done

# --- Preflight + safety guards (fail closed, before any change). --------------
# Refuses unless the current directory, deployment path, web root, and
# .mel-application marker all match the Hold application.
mel_require_cwd "${DEPLOY_PATH}"
mel_preflight "${APP_NAME}" "${DEPLOY_PATH}" "${WEB_ROOT}"
mel_validate_git "${DEPLOY_PATH}" "${BRANCH}" "${REMOTE_MATCH}"

mel_set_drush "${DEPLOY_PATH}"
DB_NAME="$("${MEL_DRUSH[@]}" status --field=db-name 2>/dev/null || echo 'unknown')"

mel_print_summary "${ENV_LABEL}" "${APP_NAME}" "${DEPLOY_PATH}" "${WEB_ROOT}" \
  "${BRANCH}" "${MEL_GIT_LOCAL}" "${MEL_GIT_REMOTE}" "${DB_NAME}"

if [[ "${MEL_GIT_LOCAL}" == "${MEL_GIT_REMOTE}" ]]; then
  mel_info "Already at origin/${BRANCH} (${MEL_GIT_LOCAL}). Deploy will re-run build steps only."
fi

if [[ "${DRY_RUN}" == "1" ]]; then
  mel_info "DRY RUN — no changes made. Would fast-forward to ${MEL_GIT_REMOTE}, back up, then composer/updb/cim/cr."
  exit 0
fi

mel_confirm "${ENV_LABEL}" "${AUTO_YES}"

# --- Backup BEFORE any change. -----------------------------------------------
mel_info "Creating pre-deploy backup…"
if ! BACKUP_DIR="$(mel_backup_create "${APP_NAME}" "${DEPLOY_PATH}" "${BACKUP_ROOT}")"; then
  mel_die "Pre-deploy backup failed — aborting before any change was made."
fi
mel_info "Backup: ${BACKUP_DIR}"

# On ANY non-zero exit from here (including a health check that fails via
# `|| mel_die`, which an ERR trap would miss), tell the operator how to roll
# back. An EXIT trap fires on every exit path; the rc guard keeps it silent on
# success.
trap 'rc=$?; [[ ${rc} -eq 0 ]] || { echo "" >&2; echo "DEPLOY FAILED (exit ${rc}). Roll back with:" >&2; echo "  bash ${SCRIPT_DIR}/rollback-hold.sh ${BACKUP_DIR}" >&2; }; exit ${rc}' EXIT

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

# --- Verify, journal, summarise (shared mel_verify; no duplicated logic). -----
DEPLOY_RESULT=0
mel_finalize_deploy "${APP_NAME}" "${ENV_LABEL}" "${DEPLOY_PATH}" "${WEB_ROOT}" \
  "${BRANCH}" "${BACKUP_DIR}" "${START_EPOCH}" "${DEPLOYMENTS_ROOT}" || DEPLOY_RESULT=$?

echo "finalise ${APP_NAME} result=${DEPLOY_RESULT} verify=${MEL_VERIFY_RESULT} at $(date -u +%FT%TZ)" >> "${LOG_FILE}"

if [[ "${DEPLOY_RESULT}" -ne 0 ]]; then
  mel_die "Deployment verification FAILED — review the report above and roll back if needed."
fi

echo ""
echo "✅ Hold deploy complete (${MEL_VERIFY_RESULT}). Log: ${LOG_FILE}"
echo "   Rollback (if needed): bash ${SCRIPT_DIR}/rollback-hold.sh ${BACKUP_DIR}"
