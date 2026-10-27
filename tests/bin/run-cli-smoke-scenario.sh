#!/usr/bin/env bash
set -euo pipefail

WP_VERSION="${1:-}"
if [[ ! "$WP_VERSION" =~ ^7\.(0|1)$ ]]; then
  echo "Usage: $0 <7.0|7.1>" >&2
  exit 64
fi

: "${WP_CORE_DIR:?WP_CORE_DIR is required}"
: "${CB_PLUGIN_FILE:?CB_PLUGIN_FILE is required}"
: "${CB_WP_CLI_PHAR:?CB_WP_CLI_PHAR is required}"
: "${WP_DB_NAME:?WP_DB_NAME is required}"
: "${WP_DB_USER:?WP_DB_USER is required}"
: "${WP_DB_PASSWORD:?WP_DB_PASSWORD is required}"
: "${WP_DB_HOST:?WP_DB_HOST is required}"

CB_CLI_TABLE_PREFIX="${CB_CLI_TABLE_PREFIX:-cbcli_}"

wp_cli() {
  php "$CB_WP_CLI_PHAR" --path="$WP_CORE_DIR" --no-color "$@"
}

info_output="$(php "$CB_WP_CLI_PHAR" --info)"
if ! grep -Eq '^WP-CLI version:[[:space:]]+2\.12\.0$' <<<"$info_output"; then
  printf '%s\n' "$info_output" >&2
  echo "[B0] WP-CLI version contract failed." >&2
  exit 1
fi
echo "[B0] WP-CLI version PASS: 2.12.0"

core_version="$(wp_cli core version)"
if [[ "$core_version" != "$WP_VERSION" ]]; then
  echo "[B0] WordPress core version mismatch: expected $WP_VERSION, got $core_version" >&2
  exit 1
fi
echo "[B0] WordPress core version PASS: $core_version"

if [[ -e "$WP_CORE_DIR/wp-config.php" ]]; then
  echo "[B0] Refusing to reuse an existing wp-config.php for the isolated CLI site." >&2
  exit 1
fi

wp_cli config create \
  --dbname="$WP_DB_NAME" \
  --dbuser="$WP_DB_USER" \
  --dbpass="$WP_DB_PASSWORD" \
  --dbhost="$WP_DB_HOST" \
  --dbprefix="$CB_CLI_TABLE_PREFIX" \
  --skip-check

wp_cli core install \
  --url='http://core-blueprint-cli.test' \
  --title='Core Blueprint CLI Harness' \
  --admin_user='cb-cli-admin' \
  --admin_password='cb-cli-password-only-for-ci' \
  --admin_email='cb-cli@example.test' \
  --skip-email

echo "[B0] WordPress CLI install PASS with prefix ${CB_CLI_TABLE_PREFIX}"

if [[ ! -f "$CB_PLUGIN_FILE" ]]; then
  echo "[B0] Core Blueprint plugin fixture missing at $CB_PLUGIN_FILE" >&2
  exit 1
fi

wp_cli plugin activate core-blueprint
if ! wp_cli plugin is-active core-blueprint >/dev/null 2>&1; then
  echo "[B0] Core Blueprint activation did not persist." >&2
  exit 1
fi
echo "[B0] Core Blueprint activation PASS"

wp_cli_flag="$(wp_cli eval 'echo ( defined( "WP_CLI" ) && true === WP_CLI ) ? "true" : "false";')"
if [[ "$wp_cli_flag" != "true" ]]; then
  echo "[B0] Real WP-CLI runtime did not expose WP_CLI === true." >&2
  exit 1
fi
echo "[B0] Real WP_CLI runtime PASS"

wp_cli help cb >/dev/null
wp_cli help cb permissions >/dev/null
echo "[B0] Core Blueprint CLI registration PASS"

status_output="$(wp_cli cb permissions status)"
if ! grep -Fq 'Core Blueprint - Permissions Status' <<<"$status_output"; then
  printf '%s\n' "$status_output" >&2
  echo "[B0] Read-only permissions status command did not return the expected Base status surface." >&2
  exit 1
fi
printf '%s\n' "$status_output"
echo "[B0] Read-only Base CLI command PASS"


# C3 clean-v1 smoke, read-only against an exclusively installed CLI site.
# No historical migration, seed, repair, delete or direct document write.
native_authority="$(wp_cli eval 'echo (string) ( \CoreBlueprint\Core\Design\Document\Authority::inspect()["status"] ?? "blocked" );')" || {
  echo "[C3] Native v1 authority inspection failed." >&2
  exit 1
}
if [[ "$native_authority" != "active" ]]; then
  echo "[C3] Native v1 authority refused: $native_authority" >&2
  exit 1
fi
native_reports="$(wp_cli eval '$read = \CoreBlueprint\Core\Design\Document\Repository::get( \CoreBlueprint\Core\Design\Document\Repository::REPORTS, "maintenance" ); echo (string) ( $read["status"] ?? "blocked" );')" || {
  echo "[C3] Read-only native Reports inspection failed." >&2
  exit 1
}
if [[ "$native_reports" != "missing" ]]; then
  echo "[C3] Fresh CLI site must have no native Reports row." >&2
  exit 1
fi
echo "[C3] clean-v1 authority and unpersisted Reports default context PASS"
echo "[B0] real WP-CLI smoke scenario PASS"
