#!/usr/bin/env bash
set -euo pipefail

ZIP_PATH="${1:-}"
EXPECTED_VERSION="${2:-}"

if [[ -z "$ZIP_PATH" || -z "$EXPECTED_VERSION" ]]; then
  echo "Usage: $0 <release.zip> <expected-version>" >&2
  exit 64
fi

if [[ ! -f "$ZIP_PATH" ]]; then
  echo "[H] Release ZIP not found: $ZIP_PATH" >&2
  exit 1
fi

entries="$(unzip -Z1 "$ZIP_PATH")"
if [[ -z "$entries" ]]; then
  echo "[H] Release ZIP is empty." >&2
  exit 1
fi

if grep -Ev '^core-blueprint/' <<<"$entries" >/dev/null; then
  echo "[H] Release ZIP contains entries outside canonical core-blueprint/ root:" >&2
  grep -Ev '^core-blueprint/' <<<"$entries" >&2 || true
  exit 1
fi

echo "[H] canonical plugin root PASS"

for forbidden in \
  'core-blueprint/.github/' \
  'core-blueprint/tests/' \
  'core-blueprint/tools/' \
  'core-blueprint/vendor/' \
  'core-blueprint/composer.json' \
  'core-blueprint/composer.lock' \
  'core-blueprint/phpunit.xml.dist' \
  'core-blueprint/.gitignore'; do
  if grep -Fq "$forbidden" <<<"$entries"; then
    echo "[H] Development-only release entry found: $forbidden" >&2
    exit 1
  fi
done

echo "[H] development-file exclusion PASS"

TMP_DIR="$(mktemp -d)"
cleanup() {
  rm -rf -- "$TMP_DIR"
}
trap cleanup EXIT

unzip -q "$ZIP_PATH" -d "$TMP_DIR"
PLUGIN_DIR="$TMP_DIR/core-blueprint"
PLUGIN_FILE="$PLUGIN_DIR/core-blueprint.php"

for required in \
  "$PLUGIN_FILE" \
  "$PLUGIN_DIR/uninstall.php" \
  "$PLUGIN_DIR/LICENSE" \
  "$PLUGIN_DIR/README.md" \
  "$PLUGIN_DIR/CHANGELOG.md" \
  "$PLUGIN_DIR/src" \
  "$PLUGIN_DIR/includes" \
  "$PLUGIN_DIR/assets" \
  "$PLUGIN_DIR/templates" \
  "$PLUGIN_DIR/languages" \
  "$PLUGIN_DIR/licenses"; do
  if [[ ! -e "$required" ]]; then
    echo "[H] Required packaged runtime path missing: ${required#$PLUGIN_DIR/}" >&2
    exit 1
  fi
done

echo "[H] required runtime payload PASS"

header_version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$PLUGIN_FILE" | head -n 1 | tr -d '\r')"
constant_version="$(sed -n "s/.*define( 'CB_CORE_VERSION',[[:space:]]*'\([^']*\)' ).*/\1/p" "$PLUGIN_FILE" | head -n 1)"

if [[ "$header_version" != "$EXPECTED_VERSION" ]]; then
  echo "[H] Packaged plugin header mismatch: expected $EXPECTED_VERSION, got $header_version" >&2
  exit 1
fi

if [[ "$constant_version" != "$EXPECTED_VERSION" ]]; then
  echo "[H] Packaged CB_CORE_VERSION mismatch: expected $EXPECTED_VERSION, got $constant_version" >&2
  exit 1
fi

echo "[H] packaged version consistency PASS: $EXPECTED_VERSION"

php_files=0
while IFS= read -r -d '' php_file; do
  php -l "$php_file" >/dev/null
  php_files=$((php_files + 1))
done < <(find "$PLUGIN_DIR" -type f -name '*.php' -print0 | sort -z)

if (( php_files == 0 )); then
  echo "[H] No PHP files found in packaged plugin." >&2
  exit 1
fi

echo "[H] packaged PHP syntax PASS: $php_files files"

js_files=0
while IFS= read -r -d '' js_file; do
  node --check "$js_file" >/dev/null
  js_files=$((js_files + 1))
done < <(find "$PLUGIN_DIR/assets" -type f -name '*.js' -print0 | sort -z)

if (( js_files == 0 )); then
  echo "[H] No JavaScript files found in packaged plugin." >&2
  exit 1
fi

echo "[H] packaged JavaScript syntax PASS: $js_files files"
echo "[H] release package boundary scenario PASS"
