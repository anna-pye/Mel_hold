#!/usr/bin/env bash

if [[ -n "${MEL_SCHEMA_SH_LOADED:-}" ]]; then
  return 0 2>/dev/null || true
fi
MEL_SCHEMA_SH_LOADED=1

_mel_schema_lib_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)" || return 70
# shellcheck source=deploy/lib/common.sh
. "${_mel_schema_lib_dir}/common.sh"
# shellcheck source=deploy/lib/errors.sh
. "${_mel_schema_lib_dir}/errors.sh"
# shellcheck source=deploy/lib/files.sh
. "${_mel_schema_lib_dir}/files.sh"
unset _mel_schema_lib_dir

mel_schema_default_path() {
  local root
  root="$(mel_project_root)" || return $?
  printf '%s\n' "${root}/schemas/deployment-manifest.schema.json"
}

mel_schema_validate_manifest() {
  local manifest_path="${1:-}"
  local schema_path="${2:-}"
  local format="${3:-${MEL_OUTPUT_FORMAT:-text}}"
  local validation_output
  local validation_status

  if [[ -z "${schema_path}" ]]; then
    schema_path="$(mel_schema_default_path)" || return $?
  fi

  mel_file_require_readable "${manifest_path}" "${format}" || return $?
  mel_file_require_readable "${schema_path}" "${format}" || return $?

  if ! mel_require_command python3; then
    mel_error_emit "${MEL_EXIT_DEPENDENCY}" "dependency" "python3 is required for manifest validation." "${format}"
    return $?
  fi

  validation_output="$(python3 - "${manifest_path}" "${schema_path}" <<'PY' 2>&1
import json
import re
import sys
from pathlib import Path

manifest_path = Path(sys.argv[1])
schema_path = Path(sys.argv[2])

SECRET_KEY_PATTERN = re.compile(r"(secret|password|token|private[_-]?key|credential)", re.IGNORECASE)
CREDENTIAL_URL_PATTERN = re.compile(r"^[a-z][a-z0-9+.-]*://[^/\s:@]+:[^/\s:@]+@", re.IGNORECASE)


class ValidationError(Exception):
    def __init__(self, message, code=20):
        super().__init__(message)
        self.code = code


def strip_comment(value):
    in_single = False
    in_double = False
    for index, char in enumerate(value):
        if char == "'" and not in_double:
            in_single = not in_single
        elif char == '"' and not in_single:
            in_double = not in_double
        elif char == "#" and not in_single and not in_double:
            if index == 0 or value[index - 1].isspace():
                return value[:index].rstrip()
    return value.rstrip()


def parse_scalar(value):
    value = strip_comment(value).strip()
    if value == "":
        return ""
    if (value.startswith('"') and value.endswith('"')) or (value.startswith("'") and value.endswith("'")):
        return value[1:-1]
    if re.fullmatch(r"-?[0-9]+", value):
        return int(value)
    if value in ("true", "false"):
        return value == "true"
    return value


def parse_manifest(text):
    root = {}
    lines = text.splitlines()
    index = 0

    while index < len(lines):
        raw = lines[index]
        line = raw.rstrip()
        stripped = line.strip()
        index += 1

        if not stripped or stripped == "---" or stripped.startswith("#"):
            continue
        if raw.startswith((" ", "\t")):
            raise ValidationError(f"Unsupported top-level indentation near line {index}: {raw}")
        if ":" not in line:
            raise ValidationError(f"Expected key/value pair near line {index}: {raw}")

        key, value = line.split(":", 1)
        key = key.strip()
        if not key:
            raise ValidationError(f"Empty key near line {index}.")
        if key in root:
            raise ValidationError(f"Duplicate key: {key}", 22)
        if SECRET_KEY_PATTERN.search(key):
            raise ValidationError(f"Secret-like key is not permitted: {key}", 23)

        value = strip_comment(value)
        if value.strip():
            root[key] = parse_scalar(value)
            continue

        items = []
        while index < len(lines):
            child_raw = lines[index]
            child = child_raw.rstrip()
            child_stripped = child.strip()
            if not child_stripped or child_stripped.startswith("#"):
                index += 1
                continue
            if not child_raw.startswith("  "):
                break
            if not child.startswith("  - "):
                raise ValidationError(f"Only arrays of objects are supported near line {index + 1}: {child_raw}")

            item = {}
            first = child[4:]
            index += 1
            if first:
                if ":" not in first:
                    raise ValidationError(f"Expected array object key/value near line {index}: {child_raw}")
                item_key, item_value = first.split(":", 1)
                item_key = item_key.strip()
                if item_key in item:
                    raise ValidationError(f"Duplicate key in {key}: {item_key}", 22)
                if SECRET_KEY_PATTERN.search(item_key):
                    raise ValidationError(f"Secret-like key is not permitted: {item_key}", 23)
                item[item_key] = parse_scalar(item_value)

            while index < len(lines):
                prop_raw = lines[index]
                prop = prop_raw.rstrip()
                prop_stripped = prop.strip()
                if not prop_stripped or prop_stripped.startswith("#"):
                    index += 1
                    continue
                if prop.startswith("  - "):
                    break
                if not prop.startswith("    "):
                    break
                prop_body = prop[4:]
                if ":" not in prop_body:
                    raise ValidationError(f"Expected object key/value near line {index + 1}: {prop_raw}")
                prop_key, prop_value = prop_body.split(":", 1)
                prop_key = prop_key.strip()
                if prop_key in item:
                    raise ValidationError(f"Duplicate key in {key}: {prop_key}", 22)
                if SECRET_KEY_PATTERN.search(prop_key):
                    raise ValidationError(f"Secret-like key is not permitted: {prop_key}", 23)
                item[prop_key] = parse_scalar(prop_value)
                index += 1

            items.append(item)

        root[key] = items

    return root


def check_secret_values(value, path="$"):
    if isinstance(value, dict):
        for key, child in value.items():
            if SECRET_KEY_PATTERN.search(str(key)):
                raise ValidationError(f"Secret-like key is not permitted at {path}.{key}", 23)
            check_secret_values(child, f"{path}.{key}")
    elif isinstance(value, list):
        for offset, child in enumerate(value):
            check_secret_values(child, f"{path}[{offset}]")
    elif isinstance(value, str) and CREDENTIAL_URL_PATTERN.search(value):
        raise ValidationError(f"Credential-bearing URL is not permitted at {path}", 23)


def type_name(value):
    if isinstance(value, bool):
        return "boolean"
    if isinstance(value, int):
        return "integer"
    if isinstance(value, str):
        return "string"
    if isinstance(value, list):
        return "array"
    if isinstance(value, dict):
        return "object"
    return type(value).__name__


def validate_schema(value, schema, path="$"):
    expected = schema.get("type")
    if expected == "object":
        if not isinstance(value, dict):
            raise ValidationError(f"{path} must be object, got {type_name(value)}.", 21)
        required = schema.get("required", [])
        for key in required:
            if key not in value:
                raise ValidationError(f"{path}.{key} is required.", 21)
        if schema.get("additionalProperties") is False:
            allowed = set(schema.get("properties", {}).keys())
            for key in value:
                if key not in allowed:
                    raise ValidationError(f"{path}.{key} is not allowed.", 21)
        for key, child_schema in schema.get("properties", {}).items():
            if key in value:
                validate_schema(value[key], child_schema, f"{path}.{key}")
        return

    if expected == "array":
        if not isinstance(value, list):
            raise ValidationError(f"{path} must be array, got {type_name(value)}.", 21)
        minimum = schema.get("minItems")
        if minimum is not None and len(value) < minimum:
            raise ValidationError(f"{path} must contain at least {minimum} item(s).", 21)
        item_schema = schema.get("items")
        if item_schema:
            for offset, child in enumerate(value):
                validate_schema(child, item_schema, f"{path}[{offset}]")
        return

    if expected == "string":
        if not isinstance(value, str):
            raise ValidationError(f"{path} must be string, got {type_name(value)}.", 21)
        minimum = schema.get("minLength")
        if minimum is not None and len(value) < minimum:
            raise ValidationError(f"{path} must not be empty.", 21)
        pattern = schema.get("pattern")
        if pattern and not re.fullmatch(pattern, value):
            raise ValidationError(f"{path} does not match required pattern.", 21)
        enum = schema.get("enum")
        if enum and value not in enum:
            raise ValidationError(f"{path} must be one of: {', '.join(enum)}.", 21)
        return

    if expected == "integer":
        if isinstance(value, bool) or not isinstance(value, int):
            raise ValidationError(f"{path} must be integer, got {type_name(value)}.", 21)
        minimum = schema.get("minimum")
        maximum = schema.get("maximum")
        if minimum is not None and value < minimum:
            raise ValidationError(f"{path} must be at least {minimum}.", 21)
        if maximum is not None and value > maximum:
            raise ValidationError(f"{path} must be at most {maximum}.", 21)
        return


try:
    schema = json.loads(schema_path.read_text(encoding="utf-8"))
    manifest = parse_manifest(manifest_path.read_text(encoding="utf-8"))
    check_secret_values(manifest)
    validate_schema(manifest, schema)
except json.JSONDecodeError as exc:
    print(f"Invalid schema JSON: {exc}", file=sys.stderr)
    sys.exit(21)
except OSError as exc:
    print(str(exc), file=sys.stderr)
    sys.exit(10)
except ValidationError as exc:
    print(str(exc), file=sys.stderr)
    sys.exit(exc.code)
PY
)"
  validation_status=$?

  if [[ "${validation_status}" -ne 0 ]]; then
    case "${validation_status}" in
      "${MEL_EXIT_SCHEMA}")
        mel_error_emit "${MEL_EXIT_SCHEMA}" "schema" "${validation_output}" "${format}"
        ;;
      "${MEL_EXIT_DUPLICATE}")
        mel_error_emit "${MEL_EXIT_DUPLICATE}" "manifest" "${validation_output}" "${format}"
        ;;
      "${MEL_EXIT_SECURITY}")
        mel_error_emit "${MEL_EXIT_SECURITY}" "security" "${validation_output}" "${format}"
        ;;
      "${MEL_EXIT_FILESYSTEM}")
        mel_error_emit "${MEL_EXIT_FILESYSTEM}" "filesystem" "${validation_output}" "${format}"
        ;;
      *)
        mel_error_emit "${MEL_EXIT_MANIFEST}" "manifest" "${validation_output}" "${format}"
        ;;
    esac
    return $?
  fi

  return 0
}
