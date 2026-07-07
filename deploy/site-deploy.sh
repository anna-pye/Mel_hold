#!/usr/bin/env bash
#
# Deploy Drupal after code exists on the server (composer, permissions, install/import).
# Usage: bash deploy/site-deploy.sh /var/www/myeventlane
#
# Requires /etc/myeventlane/production.env (see deploy/production.env.example).
# Run as the deploy user (mel); may need sudo for chown where noted.
#

set -euo pipefail

ROOT="${1:-${PWD}}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy-common.sh
source "${SCRIPT_DIR}/deploy-common.sh"

if [[ ! -f "${ROOT}/composer.json" ]]; then
  echo "Usage: $0 /path/to/myeventlane (project root with composer.json)" >&2
  exit 1
fi

mel_assert_not_staging_path "${ROOT}"

echo "==> [Environment] validating production variables"
bash "${SCRIPT_DIR}/check-mel-environment.sh" "${ROOT}"

cd "${ROOT}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
bash "${SCRIPT_DIR}/check-mel-environment.sh" "${ROOT}"

composer install --no-dev --optimize-autoloader --no-interaction

mkdir -p "${ROOT}/web/sites/default/files" "${ROOT}/private" "${ROOT}/config/sync"
chmod 775 "${ROOT}/web/sites/default/files" "${ROOT}/private" || true

if [[ ! -f "${ROOT}/web/sites/default/settings.production.php" ]]; then
  if [[ -f "${ROOT}/web/sites/default/settings.production.example.php" ]]; then
    cp "${ROOT}/web/sites/default/settings.production.example.php" "${ROOT}/web/sites/default/settings.production.php"
    echo "==> [Drupal] created settings.production.php from example — review trusted_host_patterns"
  fi
fi

DRUSH=(php -d memory_limit=512M "${ROOT}/vendor/bin/drush")

if "${DRUSH[@]}" status 2>/dev/null | grep -q 'Successful'; then
  echo "==> [Drupal] site bootstrapped; running updates"
  "${DRUSH[@]}" updatedb -y
  echo "==> [Config import] configuration import"
  "${DRUSH[@]}" cim -y || true
  echo "==> [Cache rebuild] clearing caches"
  "${DRUSH[@]}" cr
  if [[ "${MEL_DEPLOY_SKIP_VERIFY:-0}" != "1" ]]; then
    bash "${SCRIPT_DIR}/post-deploy-verify.sh" "${ROOT}"
  else
    echo "==> [Verification] skipped (MEL_DEPLOY_SKIP_VERIFY=1)"
  fi
  echo "==> [Success] site-deploy complete"
  exit 0
fi

ADMIN_PASS="${DRUSH_ACCOUNT_PASS:-$(openssl rand -base64 16)}"
echo "==> [Drupal] installing with config from config/sync"
"${DRUSH[@]}" site:install standard \
  --yes \
  --account-name="${DRUSH_ACCOUNT_NAME:-admin}" \
  --account-pass="${ADMIN_PASS}" \
  --account-mail="${DRUSH_ACCOUNT_MAIL:-admin@myeventlane.com.au}" \
  --site-mail="${DRUSH_SITE_MAIL:-info@myeventlane.com.au}" \
  --site-name="${DRUSH_SITE_NAME:-MyEventLane}" \
  --existing-config

echo "==> [Cache rebuild] clearing caches"
"${DRUSH[@]}" cr

if [[ "${MEL_DEPLOY_SKIP_VERIFY:-0}" != "1" ]]; then
  bash "${SCRIPT_DIR}/post-deploy-verify.sh" "${ROOT}"
else
  echo "==> [Verification] skipped (MEL_DEPLOY_SKIP_VERIFY=1)"
fi

echo ""
echo "Drupal admin one-time password (store in a vault; change after login): ${ADMIN_PASS}"
echo ""
echo "If the front page is not the holding home, set:"
echo "  ./vendor/bin/drush config:set system.site page.front home -y"
echo "  ./vendor/bin/drush cr"
echo ""
echo "Set file ownership for web server (adjust user/group):"
echo "  sudo chown -R mel:www-data web/sites/default/files private"
echo ""
echo "==> [Success] site-deploy complete"
