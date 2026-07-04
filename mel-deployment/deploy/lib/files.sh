#!/usr/bin/env bash

if [[ -n "${MEL_FILES_SH_LOADED:-}" ]]; then
  return 0 2>/dev/null || true
fi
MEL_FILES_SH_LOADED=1

_mel_files_lib_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)" || return 70
# shellcheck source=deploy/lib/common.sh
. "${_mel_files_lib_dir}/common.sh"
# shellcheck source=deploy/lib/errors.sh
. "${_mel_files_lib_dir}/errors.sh"
unset _mel_files_lib_dir

mel_file_require_readable() {
  local path="${1:-}"
  local format="${2:-${MEL_OUTPUT_FORMAT:-text}}"

  if [[ -z "${path}" ]]; then
    mel_error_emit "${MEL_EXIT_USAGE}" "usage" "File path is required." "${format}"
    return $?
  fi

  if [[ ! -f "${path}" ]]; then
    mel_error_emit "${MEL_EXIT_FILESYSTEM}" "filesystem" "File does not exist: ${path}" "${format}"
    return $?
  fi

  if [[ ! -r "${path}" ]]; then
    mel_error_emit "${MEL_EXIT_FILESYSTEM}" "filesystem" "File is not readable: ${path}" "${format}"
    return $?
  fi

  return 0
}

mel_path_is_inside_project() {
  local path="${1:-}"
  local root
  local absolute_path

  if [[ -z "${path}" ]]; then
    return 2
  fi

  root="$(mel_project_root)" || return $?
  if [[ -e "${path}" ]]; then
    absolute_path="$(cd "$(dirname "${path}")" && pwd -P)/$(basename "${path}")" || return 10
  else
    absolute_path="$(cd "$(dirname "${path}")" && pwd -P)/$(basename "${path}")" || return 10
  fi

  case "${absolute_path}" in
    "${root}"|"${root}"/*)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}
