#!/usr/bin/env bash
#
# verify-deployment.sh — read-only health verification for ONE MEL application.
#
# Makes no filesystem changes. Validates the target against the same allowlist
# and identity marker the deploy scripts use, then runs the shared mel_verify
# checks (single source of truth — the deploy scripts run the same function).
#
#   bash deploy/verify-deployment.sh /home/mel/sites/myeventlane_hold
#
# Exit: 0 = PASS or WARNING, 1 = FAIL, 2 = misuse/guard failure.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/mel-deploy-guards.sh
source "${SCRIPT_DIR}/lib/mel-deploy-guards.sh"

TARGET="${1:-}"
[[ -n "${TARGET}" ]] || mel_die "Usage: verify-deployment.sh <deploy path>
Allowed: /home/mel/sites/myeventlane_{hold,staging,production}"

# Read-only, but still fail closed to an allowlisted, real application dir.
mel_guard_target_path "${TARGET}"
mel_require_app_dir "${TARGET}"

MARKER="${TARGET}/.mel-application"
[[ -f "${MARKER}" ]] || mel_die "Missing application identity marker: ${MARKER}"
APP="$(tr -d '[:space:]' < "${MARKER}")"
[[ -n "${APP}" ]] || mel_die "Empty application identity marker: ${MARKER}"

mel_collect_versions "${TARGET}"
mel_verify "${APP}" "${TARGET}"
mel_print_verify

echo ""
echo "Application  : ${APP}"
echo "Drupal       : ${MEL_VER_DRUPAL}   PHP: ${MEL_VER_PHP}   Composer: ${MEL_VER_COMPOSER}"
echo "Overall      : ${MEL_VERIFY_RESULT}"

case "${MEL_VERIFY_RESULT}" in
  PASS|WARNING) exit 0 ;;
  *) exit 1 ;;
esac
