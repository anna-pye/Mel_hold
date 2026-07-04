#!/usr/bin/env bash

if [[ -n "${MEL_ERRORS_SH_LOADED:-}" ]]; then
  return 0 2>/dev/null || true
fi
MEL_ERRORS_SH_LOADED=1

MEL_EXIT_OK=0
MEL_EXIT_USAGE=2
MEL_EXIT_FILESYSTEM=10
MEL_EXIT_MANIFEST=20
MEL_EXIT_SCHEMA=21
MEL_EXIT_DUPLICATE=22
MEL_EXIT_SECURITY=23
MEL_EXIT_DEPENDENCY=30
MEL_EXIT_INTERNAL=70

_mel_error_json_escape() {
  local value="${1:-}"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  value="${value//$'\r'/\\r}"
  value="${value//$'\t'/\\t}"
  printf '%s' "${value}"
}

mel_error_category_for_code() {
  case "${1:-}" in
    "${MEL_EXIT_USAGE}") printf 'usage\n' ;;
    "${MEL_EXIT_FILESYSTEM}") printf 'filesystem\n' ;;
    "${MEL_EXIT_MANIFEST}") printf 'manifest\n' ;;
    "${MEL_EXIT_SCHEMA}") printf 'schema\n' ;;
    "${MEL_EXIT_DUPLICATE}") printf 'manifest\n' ;;
    "${MEL_EXIT_SECURITY}") printf 'security\n' ;;
    "${MEL_EXIT_DEPENDENCY}") printf 'dependency\n' ;;
    "${MEL_EXIT_INTERNAL}") printf 'internal\n' ;;
    *) printf 'unknown\n' ;;
  esac
}

mel_error_emit() {
  local code="${1:-${MEL_EXIT_INTERNAL}}"
  local category="${2:-}"
  local message="${3:-Unspecified error}"
  local format="${4:-${MEL_OUTPUT_FORMAT:-text}}"

  if [[ -z "${category}" ]]; then
    category="$(mel_error_category_for_code "${code}")"
  fi

  case "${format}" in
    json)
      printf '{"status":"error","code":%s,"category":"%s","message":"%s"}\n' \
        "${code}" \
        "$(_mel_error_json_escape "${category}")" \
        "$(_mel_error_json_escape "${message}")" >&2
      ;;
    *)
      printf 'ERROR [%s] (%s): %s\n' "${category}" "${code}" "${message}" >&2
      ;;
  esac

  return "${code}"
}
