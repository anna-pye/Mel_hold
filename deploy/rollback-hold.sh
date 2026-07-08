#!/usr/bin/env bash
#
# rollback-hold.sh — roll MEL Hold back to a previous backup (code + database).
# Hardcoded target. Run ON THE SERVER:
#
#   bash /home/mel/sites/myeventlane_hold/deploy/rollback-hold.sh [BACKUP_DIR|--list]
#
# With no argument, the most recent backup for this app is used.
#
set -euo pipefail

readonly APP_NAME="myeventlane_hold"
readonly ENV_LABEL="hold"
readonly DEPLOY_PATH="/home/mel/sites/myeventlane_hold"
readonly BACKUP_ROOT="/home/mel/shared/backups"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/mel-deploy-guards.sh
source "${SCRIPT_DIR}/lib/mel-deploy-guards.sh"

mel_preflight "${APP_NAME}" "${DEPLOY_PATH}"

APP_BACKUPS="${BACKUP_ROOT}/${APP_NAME}"

if [[ "${1:-}" == "--list" ]]; then
  mel_info "Backups for ${APP_NAME}:"
  ls -1 "${APP_BACKUPS}" 2>/dev/null || mel_die "No backups found at ${APP_BACKUPS}"
  exit 0
fi

BACKUP_DIR="${1:-}"
if [[ -z "${BACKUP_DIR}" ]]; then
  latest="$(ls -1 "${APP_BACKUPS}" 2>/dev/null | tail -n 1)"
  [[ -n "${latest}" ]] || mel_die "No backups found at ${APP_BACKUPS}"
  BACKUP_DIR="${APP_BACKUPS}/${latest}"
fi

mel_info "Rolling ${ENV_LABEL} back using: ${BACKUP_DIR}"
mel_confirm "${ENV_LABEL}" "0"
mel_rollback_restore "${DEPLOY_PATH}" "${BACKUP_DIR}" "${APP_BACKUPS}"
