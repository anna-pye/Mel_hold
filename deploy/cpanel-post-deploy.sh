#!/usr/bin/env bash
#
# Post-deploy on cPanel / shared hosting (no /etc/myeventlane/production.env required).
# Usage: bash deploy/cpanel-post-deploy.sh [/home/mel]
#

set -euo pipefail

ROOT="${1:-${PWD}}"
if [[ ! -f "${ROOT}/composer.json" ]]; then
  echo "Usage: $0 /path/to/project (composer.json at root)" >&2
  exit 1
fi

cd "${ROOT}"

composer install --no-dev --optimize-autoloader --no-interaction

DRUSH=(php -d memory_limit=512M "${ROOT}/vendor/bin/drush")
if "${DRUSH[@]}" status 2>/dev/null | grep -q 'Successful'; then
  "${DRUSH[@]}" updatedb -y
  "${DRUSH[@]}" cr
else
  echo "Warning: Drupal not bootstrapped; skipped drush updatedb/cr." >&2
fi

echo "cpanel-post-deploy: done."
