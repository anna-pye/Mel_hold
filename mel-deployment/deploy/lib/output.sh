#!/usr/bin/env bash

if [[ -n "${MEL_OUTPUT_SH_LOADED:-}" ]]; then
  return 0 2>/dev/null || true
fi
MEL_OUTPUT_SH_LOADED=1

mel_json_escape() {
  local value="${1:-}"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  value="${value//$'\r'/\\r}"
  value="${value//$'\t'/\\t}"
  printf '%s' "${value}"
}

mel_output_status() {
  local status="${1:-ok}"
  local message="${2:-}"
  local format="${3:-${MEL_OUTPUT_FORMAT:-text}}"

  case "${format}" in
    json)
      printf '{"status":"%s","message":"%s"}\n' \
        "$(mel_json_escape "${status}")" \
        "$(mel_json_escape "${message}")"
      ;;
    *)
      if [[ -n "${message}" ]]; then
        printf '%s: %s\n' "${status}" "${message}"
      else
        printf '%s\n' "${status}"
      fi
      ;;
  esac
}

mel_output_kv() {
  local key="${1:-}"
  local value="${2:-}"
  local format="${3:-${MEL_OUTPUT_FORMAT:-text}}"

  case "${format}" in
    json)
      printf '{"%s":"%s"}\n' "$(mel_json_escape "${key}")" "$(mel_json_escape "${value}")"
      ;;
    *)
      printf '%s: %s\n' "${key}" "${value}"
      ;;
  esac
}
