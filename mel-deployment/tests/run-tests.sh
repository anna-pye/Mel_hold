#!/usr/bin/env bash

set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/mel-deployment-tests.XXXXXX")"
FAILURES=0

cleanup() {
  rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

record_failure() {
  printf 'not ok: %s\n' "$1" >&2
  FAILURES=$((FAILURES + 1))
}

run_expect() {
  local expected_status="$1"
  local label="$2"
  shift 2

  "$@" >"${TMP_DIR}/${label}.out" 2>"${TMP_DIR}/${label}.err"
  local actual_status=$?

  if [[ "${actual_status}" -ne "${expected_status}" ]]; then
    record_failure "${label} expected exit ${expected_status}, got ${actual_status}"
    return
  fi

  printf 'ok: %s\n' "${label}"
}

assert_output_contains() {
  local label="$1"
  local file="$2"
  local expected="$3"

  if ! python3 - "$file" "$expected" <<'PY'
import sys
from pathlib import Path

content = Path(sys.argv[1]).read_text(encoding="utf-8")
needle = sys.argv[2]
sys.exit(0 if needle in content else 1)
PY
  then
    record_failure "${label} missing expected output: ${expected}"
  fi
}

write_manifest() {
  local path="$1"
  local body="$2"
  printf '%s\n' "${body}" >"${path}"
}

run_expect 0 "validate-help" bash "${ROOT}/deploy/bin/mel-validate" --help
assert_output_contains "validate-help" "${TMP_DIR}/validate-help.out" "Usage: mel-validate"

run_expect 0 "info-help" bash "${ROOT}/deploy/bin/mel-info" --help
assert_output_contains "info-help" "${TMP_DIR}/info-help.out" "Usage: mel-info"

run_expect 0 "schema-help" bash "${ROOT}/deploy/bin/mel-schema" --help
assert_output_contains "schema-help" "${TMP_DIR}/schema-help.out" "Usage: mel-schema"

run_expect 0 "schema-valid" bash "${ROOT}/deploy/bin/mel-schema"
run_expect 0 "examples-valid" bash "${ROOT}/deploy/bin/mel-validate" \
  "${ROOT}/examples/hold-production.example.yml" \
  "${ROOT}/examples/platform-staging.example.yml"
run_expect 0 "json-output" bash "${ROOT}/deploy/bin/mel-validate" --format json \
  "${ROOT}/examples/hold-production.example.yml"
assert_output_contains "json-output" "${TMP_DIR}/json-output.out" '"status":"ok"'

write_manifest "${TMP_DIR}/missing-required.yml" "---
deployment_id: missing-required
repository: github.com/example/app.git
environment: staging
composer_root: app
document_root: app/web
release_root: /example/allowlisted/app/releases
shared_root: /example/allowlisted/app/shared
current_link: /example/allowlisted/app/current
health_checks:
  - name: homepage
    url: https://app.example.test/
    expected_status: 200"
run_expect 21 "missing-required" bash "${ROOT}/deploy/bin/mel-validate" "${TMP_DIR}/missing-required.yml"
assert_output_contains "missing-required" "${TMP_DIR}/missing-required.err" "branch is required"

write_manifest "${TMP_DIR}/duplicate-key.yml" "---
deployment_id: duplicate-key
deployment_id: duplicate-key-again
repository: github.com/example/app.git
branch: main
environment: staging
composer_root: app
document_root: app/web
release_root: /example/allowlisted/app/releases
shared_root: /example/allowlisted/app/shared
current_link: /example/allowlisted/app/current
health_checks:
  - name: homepage
    url: https://app.example.test/
    expected_status: 200"
run_expect 22 "duplicate-key" bash "${ROOT}/deploy/bin/mel-validate" "${TMP_DIR}/duplicate-key.yml"

write_manifest "${TMP_DIR}/invalid-enum.yml" "---
deployment_id: invalid-enum
repository: github.com/example/app.git
branch: main
environment: prod
composer_root: app
document_root: app/web
release_root: /example/allowlisted/app/releases
shared_root: /example/allowlisted/app/shared
current_link: /example/allowlisted/app/current
health_checks:
  - name: homepage
    url: https://app.example.test/
    expected_status: 200"
run_expect 21 "invalid-enum" bash "${ROOT}/deploy/bin/mel-validate" "${TMP_DIR}/invalid-enum.yml"

write_manifest "${TMP_DIR}/secret-key.yml" "---
deployment_id: secret-key
repository: github.com/example/app.git
branch: main
environment: staging
composer_root: app
document_root: app/web
release_root: /example/allowlisted/app/releases
shared_root: /example/allowlisted/app/shared
current_link: /example/allowlisted/app/current
api_token: not-a-real-token
health_checks:
  - name: homepage
    url: https://app.example.test/
    expected_status: 200"
run_expect 23 "secret-key" bash "${ROOT}/deploy/bin/mel-validate" "${TMP_DIR}/secret-key.yml"

write_manifest "${TMP_DIR}/duplicate-a.yml" "---
deployment_id: duplicate-target
repository: github.com/example/a.git
branch: main
environment: staging
composer_root: app
document_root: app/web
release_root: /example/allowlisted/a/releases
shared_root: /example/allowlisted/a/shared
current_link: /example/allowlisted/a/current
health_checks:
  - name: homepage
    url: https://a.example.test/
    expected_status: 200"
write_manifest "${TMP_DIR}/duplicate-b.yml" "---
deployment_id: duplicate-target
repository: github.com/example/b.git
branch: main
environment: staging
composer_root: app
document_root: app/web
release_root: /example/allowlisted/b/releases
shared_root: /example/allowlisted/b/shared
current_link: /example/allowlisted/b/current
health_checks:
  - name: homepage
    url: https://b.example.test/
    expected_status: 200"
run_expect 22 "duplicate-deployment-id" bash "${ROOT}/deploy/bin/mel-validate" \
  "${TMP_DIR}/duplicate-a.yml" \
  "${TMP_DIR}/duplicate-b.yml"

run_expect 2 "validate-no-args" bash "${ROOT}/deploy/bin/mel-validate"

if [[ "${FAILURES}" -ne 0 ]]; then
  printf '%s test(s) failed.\n' "${FAILURES}" >&2
  exit 1
fi

printf 'All tests passed.\n'
