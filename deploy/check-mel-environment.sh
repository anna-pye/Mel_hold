#!/usr/bin/env bash
#
# Fail deploy when mel.environment resolves to local (site would stay noindex).
# Usage: bash deploy/check-mel-environment.sh [/path/to/myeventlane]
#
# Loads /etc/myeventlane/production.env when present (same as site-deploy.sh).
#

set -euo pipefail

ROOT="${1:-${PWD}}"
if [[ ! -f "${ROOT}/composer.json" ]]; then
  echo "Usage: $0 /path/to/myeventlane (project root with composer.json)" >&2
  exit 1
fi

ENV_FILE="/etc/myeventlane/production.env"
if [[ -f "${ENV_FILE}" ]]; then
  set -a
  # shellcheck source=/dev/null
  . "${ENV_FILE}"
  set +a
fi

cd "${ROOT}"
DRUSH=(php -d memory_limit=512M "${ROOT}/vendor/bin/drush")

if ! "${DRUSH[@]}" status 2>/dev/null | grep -q 'Successful'; then
  echo "check-mel-environment: Drupal not bootstrapped; skipping mel.environment check." >&2
  exit 0
fi

ENV="$("${DRUSH[@]}" php:eval "echo \Drupal::service('settings')->get('mel.environment') ?: 'local';")"

if [[ "${ENV}" == "local" ]]; then
  echo "ERROR: mel.environment is 'local'. Set MEL_ENVIRONMENT=production in ${ENV_FILE} before deploying." >&2
  exit 1
fi

echo "OK: mel.environment=${ENV}"
