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
else
  echo "Warning: ${ENV_FILE} not found. Export DRUPAL_* and secrets before Drush." >&2
fi

cd "${ROOT}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
bash "${SCRIPT_DIR}/check-mel-environment.sh" "${ROOT}"

composer install --no-dev --optimize-autoloader --no-interaction

mkdir -p "${ROOT}/web/sites/default/files" "${ROOT}/private" "${ROOT}/config/sync"
chmod 775 "${ROOT}/web/sites/default/files" "${ROOT}/private" || true

if [[ ! -f "${ROOT}/web/sites/default/settings.production.php" ]]; then
  if [[ -f "${ROOT}/web/sites/default/settings.production.example.php" ]]; then
    cp "${ROOT}/web/sites/default/settings.production.example.php" "${ROOT}/web/sites/default/settings.production.php"
    echo "Created settings.production.php from example — review trusted_host_patterns."
  fi
fi

if [[ -z "${DRUPAL_HASH_SALT:-}" || -z "${WAITLIST_TOKEN_SECRET:-}" ]]; then
  echo "Set DRUPAL_HASH_SALT and WAITLIST_TOKEN_SECRET in ${ENV_FILE} before install." >&2
  exit 1
fi

DRUSH=(php -d memory_limit=512M "${ROOT}/vendor/bin/drush")

if "${DRUSH[@]}" status 2>/dev/null | grep -q 'Successful'; then
  echo "Site already bootstrapped; importing config and clearing caches."
  "${DRUSH[@]}" updatedb -y
  "${DRUSH[@]}" cim -y || true
  "${DRUSH[@]}" cr
  exit 0
fi

ADMIN_PASS="${DRUSH_ACCOUNT_PASS:-$(openssl rand -base64 16)}"
echo "Installing Drupal with config from config/sync (standard profile + existing config)…"
"${DRUSH[@]}" site:install standard \
  --yes \
  --account-name="${DRUSH_ACCOUNT_NAME:-admin}" \
  --account-pass="${ADMIN_PASS}" \
  --account-mail="${DRUSH_ACCOUNT_MAIL:-admin@myeventlane.com.au}" \
  --site-mail="${DRUSH_SITE_MAIL:-info@myeventlane.com.au}" \
  --site-name="${DRUSH_SITE_NAME:-MyEventLane}" \
  --existing-config

"${DRUSH[@]}" cr

echo ""
echo "Drupal admin one-time password (store in a vault; change after login): ${ADMIN_PASS}"
echo ""

echo ""
echo "If the front page is not the holding home, set:"
echo "  ./vendor/bin/drush config:set system.site page.front home -y"
echo "  ./vendor/bin/drush cr"
echo ""
echo "Set file ownership for web server (adjust user/group):"
echo "  sudo chown -R mel:www-data web/sites/default/files private"
echo ""
