#!/usr/bin/env bash

if [[ -n "${MEL_MANIFEST_SH_LOADED:-}" ]]; then
  return 0 2>/dev/null || true
fi
MEL_MANIFEST_SH_LOADED=1

_mel_manifest_lib_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)" || return 70
# shellcheck source=deploy/lib/common.sh
. "${_mel_manifest_lib_dir}/common.sh"
# shellcheck source=deploy/lib/errors.sh
. "${_mel_manifest_lib_dir}/errors.sh"
# shellcheck source=deploy/lib/output.sh
. "${_mel_manifest_lib_dir}/output.sh"
# shellcheck source=deploy/lib/schema.sh
. "${_mel_manifest_lib_dir}/schema.sh"
unset _mel_manifest_lib_dir

mel_manifest_deployment_id() {
  local manifest_path="${1:-}"

  if [[ -z "${manifest_path}" ]]; then
    return "${MEL_EXIT_USAGE}"
  fi

  python3 - "${manifest_path}" <<'PY'
import re
import sys
from pathlib import Path

manifest = Path(sys.argv[1])
for raw in manifest.read_text(encoding="utf-8").splitlines():
    if re.match(r"^[A-Za-z0-9_-]+\s*:", raw):
        key, value = raw.split(":", 1)
        if key.strip() == "deployment_id":
            print(value.strip().strip("'\""))
            sys.exit(0)
sys.exit(20)
PY
}

mel_manifest_validate_file() {
  local manifest_path="${1:-}"
  local schema_path="${2:-}"
  local format="${3:-${MEL_OUTPUT_FORMAT:-text}}"

  mel_schema_validate_manifest "${manifest_path}" "${schema_path}" "${format}"
}

mel_manifest_validate_files() {
  local schema_path="${1:-}"
  local format="${2:-${MEL_OUTPUT_FORMAT:-text}}"
  shift 2 || return "${MEL_EXIT_USAGE}"

  local manifest_path
  local deployment_id
  local seen_ids=""
  local validated_count=0

  if [[ "$#" -eq 0 ]]; then
    mel_error_emit "${MEL_EXIT_USAGE}" "usage" "At least one manifest path is required." "${format}"
    return $?
  fi

  for manifest_path in "$@"; do
    mel_manifest_validate_file "${manifest_path}" "${schema_path}" "${format}" || return $?
    deployment_id="$(mel_manifest_deployment_id "${manifest_path}")" || {
      mel_error_emit "${MEL_EXIT_MANIFEST}" "manifest" "Unable to read deployment_id from ${manifest_path}." "${format}"
      return $?
    }

    case "
${seen_ids}
" in
      *"
${deployment_id}
"*)
        mel_error_emit "${MEL_EXIT_DUPLICATE}" "manifest" "Duplicate deployment_id across manifests: ${deployment_id}" "${format}"
        return $?
        ;;
    esac

    seen_ids="${seen_ids}
${deployment_id}"
    validated_count=$((validated_count + 1))
  done

  case "${format}" in
    json)
      printf '{"status":"ok","validated_manifests":%s}\n' "${validated_count}"
      ;;
    *)
      printf 'ok: validated %s manifest(s)\n' "${validated_count}"
      ;;
  esac

  return 0
}
