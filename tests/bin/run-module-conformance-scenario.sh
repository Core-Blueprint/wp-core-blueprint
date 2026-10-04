#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
REQUEST="$ROOT/tests/module-conformance/request.php"

: "${WP_CORE_DIR:?WP_CORE_DIR is required}"
: "${CB_PLUGIN_FILE:?CB_PLUGIN_FILE is required}"
: "${WP_DB_NAME:?WP_DB_NAME is required}"
: "${WP_DB_USER:?WP_DB_USER is required}"
: "${WP_DB_PASSWORD:?WP_DB_PASSWORD is required}"
: "${WP_DB_HOST:?WP_DB_HOST is required}"

export CB_B3_TABLE_PREFIX="${CB_B3_TABLE_PREFIX:-cbb3_}"

cleanup() {
  php "$REQUEST" cleanup >/dev/null 2>&1 || true
  rm -f -- "$WP_CORE_DIR/wp-content/mu-plugins/cb-b3-mail-guard.php"
}
trap cleanup EXIT

run_stage() {
  local stage="$1"
  local output

  echo "[B3] request stage: $stage"
  if ! output="$(php "$REQUEST" "$stage" 2>&1)"; then
    printf '%s\n' "$output"
    return 1
  fi

  printf '%s\n' "$output"
  if ! grep -Fq "[B3] $stage PASS" <<<"$output"; then
    echo "[B3] $stage FAIL: child process exited without its explicit PASS marker." >&2
    return 1
  fi
}

# Every stage is deliberately a separate PHP process. Database/config/content
# persist through one isolated WordPress site while request-local hooks/statics
# are rebuilt from the canonical module state on every boot.
run_stage install
run_stage activate
run_stage seed-enable
run_stage verify-on-screen
run_stage verify-on-admin-post
run_stage disable
run_stage verify-off-screen
run_stage verify-off-admin-post
run_stage reenable
run_stage verify-restored-screen
run_stage verify-restored-admin-post

echo "[B3] full 11-module request-boundary conformance matrix PASS"
