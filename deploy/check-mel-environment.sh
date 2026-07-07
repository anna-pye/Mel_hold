#!/usr/bin/env bash
#
# Validate required production environment variables before deploy.
# Usage: bash deploy/check-mel-environment.sh [/path/to/project]
#
# Loads /etc/myeventlane/production.env when present, then checks the shell
# environment. Never prints secret values.
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

ENV_FILE="${MEL_PRODUCTION_ENV_FILE:-/etc/myeventlane/production.env}"
if mel_load_production_env; then
  echo "==> [Environment] Loaded ${ENV_FILE}"
else
  echo "==> [Environment] ${ENV_FILE} not found; checking exported variables"
fi

missing=()
for var in MEL_ENVIRONMENT DRUPAL_BASE_URL DRUPAL_HASH_SALT WAITLIST_TOKEN_SECRET; do
  if [[ -z "${!var:-}" ]]; then
    missing+=("${var}")
  fi
done

if [[ ${#missing[@]} -gt 0 ]]; then
  echo "ERROR: Missing required environment variables: ${missing[*]}" >&2
  echo "Set them in ${ENV_FILE} or export them before running deploy." >&2
  exit 1
fi

if [[ ! "${DRUPAL_BASE_URL}" =~ ^https?:// ]]; then
  echo "ERROR: DRUPAL_BASE_URL must be an absolute http(s) URL (no trailing path required)." >&2
  exit 1
fi

if [[ "${DRUPAL_BASE_URL}" == */ ]]; then
  echo "WARNING: DRUPAL_BASE_URL should not have a trailing slash." >&2
fi

echo "==> [Environment] MEL_ENVIRONMENT=${MEL_ENVIRONMENT}"
echo "==> [Environment] DRUPAL_BASE_URL=${DRUPAL_BASE_URL}"
echo "==> [Environment] Required secrets present (values not shown)"
