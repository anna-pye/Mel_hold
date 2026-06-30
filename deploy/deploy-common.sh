#!/usr/bin/env bash
#
# Shared helpers for MEL deployment scripts (holding site only).
# Source from other deploy/*.sh scripts; do not execute directly.
#

# Abort if DEPLOY_PATH targets the staging deployment (off limits).
mel_assert_not_staging_path() {
  local path="${1:-}"
  if [[ -z "${path}" ]]; then
    echo "ERROR: mel_assert_not_staging_path requires a path." >&2
    return 1
  fi

  local normalized="${path%/}"
  case "${normalized}" in
    /home/mel/staging|/home/mel/staging/current|/home/mel/staging-repo)
      echo "ERROR: Refusing to deploy to staging path: ${path}" >&2
      echo "Staging (/home/mel/staging*) is off limits for holding-site deploy scripts." >&2
      return 1
      ;;
  esac

  if [[ "${normalized}" == /home/mel/staging/* ]]; then
    echo "ERROR: Refusing to deploy under staging path: ${path}" >&2
    return 1
  fi

  return 0
}

# Load server environment when /etc/myeventlane/production.env exists.
mel_load_production_env() {
  local env_file="${MEL_PRODUCTION_ENV_FILE:-/etc/myeventlane/production.env}"
  if [[ -f "${env_file}" ]]; then
    set -a
    # shellcheck source=/dev/null
    . "${env_file}"
    set +a
    return 0
  fi
  return 1
}
