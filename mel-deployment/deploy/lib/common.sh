#!/usr/bin/env bash

if [[ -n "${MEL_COMMON_SH_LOADED:-}" ]]; then
  return 0 2>/dev/null || true
fi
MEL_COMMON_SH_LOADED=1

MEL_VERSION="0.2.0"

mel_project_root() {
  local source_dir
  source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)" || return 70
  cd "${source_dir}/../.." && pwd -P
}

mel_version() {
  printf '%s\n' "${MEL_VERSION}"
}

mel_is_supported_output_format() {
  case "${1:-}" in
    text|json)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

mel_output_format_or_default() {
  local requested="${1:-${MEL_OUTPUT_FORMAT:-text}}"

  if mel_is_supported_output_format "${requested}"; then
    printf '%s\n' "${requested}"
    return 0
  fi

  return 2
}

mel_require_command() {
  local command_name="${1:-}"

  if [[ -z "${command_name}" ]]; then
    return 2
  fi

  command -v "${command_name}" >/dev/null 2>&1
}
