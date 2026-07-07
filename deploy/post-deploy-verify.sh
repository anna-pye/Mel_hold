#!/usr/bin/env bash
#
# Post-deploy verification for the holding site.
# Usage: bash deploy/post-deploy-verify.sh [/path/to/project]
#

set -euo pipefail

ROOT="${1:-${PWD}}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy-common.sh
source "${SCRIPT_DIR}/deploy-common.sh"

if [[ ! -f "${ROOT}/composer.json" ]]; then
  echo "ERROR: Project root not found (composer.json missing at ${ROOT})." >&2
  exit 1
fi

mel_assert_not_staging_path "${ROOT}"
cd "${ROOT}"

echo "==> [Verification] Checking Composer"
if ! command -v composer >/dev/null 2>&1; then
  echo "ERROR: composer not found in PATH." >&2
  exit 1
fi

echo "==> [Verification] Checking vendor/autoload.php"
if [[ ! -f "${ROOT}/vendor/autoload.php" ]]; then
  echo "ERROR: vendor/autoload.php is missing. Run composer install." >&2
  exit 1
fi

DRUSH=(php -d memory_limit=512M "${ROOT}/vendor/bin/drush")

echo "==> [Verification] drush status"
if ! "${DRUSH[@]}" status; then
  echo "ERROR: drush status failed (Drupal did not bootstrap)." >&2
  exit 1
fi

echo "==> [Verification] drush config:status"
if ! "${DRUSH[@]}" config:status; then
  echo "WARNING: Active configuration differs from config/sync (see output above)." >&2
fi

echo "==> [Verification] maintenance mode"
maintenance_mode="$("${DRUSH[@]}" state:get system.maintenance_mode --format=string 2>/dev/null || echo "0")"
if [[ "${maintenance_mode}" == "1" ]]; then
  echo "ERROR: Site is left in maintenance mode." >&2
  exit 1
fi
echo "==> [Verification] maintenance mode is off"

if "${DRUSH[@]}" pm:list --status=enabled --type=module --format=list 2>/dev/null | grep -qx 'simple_sitemap'; then
  echo "==> [Verification] simple_sitemap generate"
  "${DRUSH[@]}" simple-sitemap:generate
else
  echo "==> [Verification] simple_sitemap not enabled; skipping sitemap generation"
fi

echo "==> [Success] Post-deploy verification passed"
