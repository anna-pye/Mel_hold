#!/usr/bin/env bash
#
# Post-deploy on cPanel / shared hosting.
# Usage: bash deploy/cpanel-post-deploy.sh [/home/mel]
#
# Environment: load /etc/myeventlane/production.env when present, or export
# MEL_ENVIRONMENT, DRUPAL_BASE_URL, DRUPAL_HASH_SALT, and WAITLIST_TOKEN_SECRET
# before Drush runs (see deploy/production.env.example).
#

set -euo pipefail

ROOT="${1:-${PWD}}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy-common.sh
source "${SCRIPT_DIR}/deploy-common.sh"

if [[ ! -f "${ROOT}/composer.json" ]]; then
  echo "Usage: $0 /path/to/project (composer.json at root)" >&2
  exit 1
fi

mel_assert_not_staging_path "${ROOT}"

echo "==> [Environment] validating production variables"
bash "${SCRIPT_DIR}/check-mel-environment.sh" "${ROOT}"

cd "${ROOT}"

echo "==> [Composer] install (production)"
composer install --no-dev --optimize-autoloader --no-interaction

DRUSH=(php -d memory_limit=512M "${ROOT}/vendor/bin/drush")
if "${DRUSH[@]}" status 2>/dev/null | grep -q 'Successful'; then
  echo "==> [Drupal] running database updates"
  "${DRUSH[@]}" updatedb -y
  echo "==> [Cache rebuild] clearing caches"
  "${DRUSH[@]}" cr
else
  echo "WARNING: Drupal not bootstrapped; skipped drush updatedb/cr." >&2
fi

if [[ "${MEL_DEPLOY_SKIP_VERIFY:-0}" != "1" ]]; then
  bash "${SCRIPT_DIR}/post-deploy-verify.sh" "${ROOT}"
else
  echo "==> [Verification] skipped (MEL_DEPLOY_SKIP_VERIFY=1)"
fi

echo "==> [Success] cpanel-post-deploy complete"
