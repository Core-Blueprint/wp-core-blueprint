#!/usr/bin/env bash
set -euo pipefail

CANDIDATE_ZIP="${1:-}"
PREVIOUS_ZIP="${2:-}"
CANDIDATE_VERSION="${3:-}"
PREVIOUS_VERSION="${4:-}"

if [[ -z "$CANDIDATE_ZIP" || -z "$PREVIOUS_ZIP" || -z "$CANDIDATE_VERSION" || -z "$PREVIOUS_VERSION" ]]; then
  echo "Usage: $0 <candidate.zip> <previous.zip> <candidate-version> <previous-version>" >&2
  exit 64
fi

: "${CB_WP_CLI_PHAR:?CB_WP_CLI_PHAR is required}"
: "${WP_DB_NAME:?WP_DB_NAME is required}"
: "${WP_DB_USER:?WP_DB_USER is required}"
: "${WP_DB_PASSWORD:?WP_DB_PASSWORD is required}"
: "${WP_DB_HOST:?WP_DB_HOST is required}"

for file in "$CANDIDATE_ZIP" "$PREVIOUS_ZIP" "$CB_WP_CLI_PHAR"; do
  if [[ ! -f "$file" ]]; then
    echo "[H] Required file missing: $file" >&2
    exit 1
  fi
done

WP_VERSION="7.0"
TMP_ROOT="$(mktemp -d)"
cleanup() {
  rm -rf -- "$TMP_ROOT"
}
trap cleanup EXIT

wp_cli() {
  local site_dir="$1"
  shift
  php "$CB_WP_CLI_PHAR" --path="$site_dir" --no-color "$@"
}

prepare_site() {
  local site_dir="$1"
  local prefix="$2"
  local url="$3"
  local title="$4"

  mkdir -p "$site_dir"
  curl --fail --silent --show-error --location \
    "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" \
    | tar -xz --strip-components=1 -C "$site_dir"

  wp_cli "$site_dir" config create \
    --dbname="$WP_DB_NAME" \
    --dbuser="$WP_DB_USER" \
    --dbpass="$WP_DB_PASSWORD" \
    --dbhost="$WP_DB_HOST" \
    --dbprefix="$prefix" \
    --skip-check

  wp_cli "$site_dir" config set WP_DEBUG true --raw
  wp_cli "$site_dir" config set WP_DEBUG_LOG true --raw
  wp_cli "$site_dir" config set WP_DEBUG_DISPLAY false --raw

  wp_cli "$site_dir" core install \
    --url="$url" \
    --title="$title" \
    --admin_user='cb-release-admin' \
    --admin_password='cb-release-password-only-for-ci' \
    --admin_email='cb-release@example.test' \
    --skip-email
}

assert_clean_debug_log() {
  local site_dir="$1"
  local debug_log="$site_dir/wp-content/debug.log"
  if [[ -s "$debug_log" ]]; then
    echo "[H] WordPress debug.log is not empty:" >&2
    cat "$debug_log" >&2
    exit 1
  fi
}

assert_active_version() {
  local site_dir="$1"
  local expected="$2"
  local actual

  if ! wp_cli "$site_dir" plugin is-active core-blueprint >/dev/null 2>&1; then
    echo "[H] Core Blueprint is not active at $site_dir" >&2
    exit 1
  fi

  actual="$(wp_cli "$site_dir" plugin get core-blueprint --field=version)"
  if [[ "$actual" != "$expected" ]]; then
    echo "[H] Plugin version mismatch: expected $expected, got $actual" >&2
    exit 1
  fi

  runtime_version="$(wp_cli "$site_dir" eval 'echo defined( "CB_CORE_VERSION" ) ? CB_CORE_VERSION : "missing";')"
  if [[ "$runtime_version" != "$expected" ]]; then
    echo "[H] Runtime version mismatch: expected $expected, got $runtime_version" >&2
    exit 1
  fi
}

assert_packaged_svg_sanitizer() {
  local site_dir="$1"
  local result

  result="$(wp_cli "$site_dir" eval '
$version = \CB\Core\MediaFormats\Svg\Sanitizer::VERSION;
$dtd = wp_tempnam("cb-svg-package-dtd.svg");
$css = wp_tempnam("cb-svg-package-css.svg");
$loop = wp_tempnam("cb-svg-package-loop.svg");
if (!is_string($dtd) || !is_string($css) || !is_string($loop)) {
    echo "temp-file-failed";
    return;
}

try {
    file_put_contents($dtd, "<?xml version=\"1.0\"?><!DOCTYPE svg [<!ENTITY Tab \"#\">]><svg xmlns=\"http://www.w3.org/2000/svg\"><a href=\"&Tab;javascript:alert(1)\"><text>x</text></a></svg>");
    $dtd_result = \CB\Core\MediaFormats\Svg\Sanitizer::sanitize_file($dtd);
    $dtd_ok = is_wp_error($dtd_result) && $dtd_result->get_error_code() === "cb_media_formats_svg_invalid";

    file_put_contents($css, "<svg xmlns=\"http://www.w3.org/2000/svg\"><style>@import url(https://example.invalid/a.css);rect{fill:url(//example.invalid/fill.svg#x)}</style><rect width=\"10\" height=\"10\" style=\"stroke:url(https://example.invalid/stroke.svg#x)\"/></svg>");
    $css_result = \CB\Core\MediaFormats\Svg\Sanitizer::sanitize_file($css);
    $css_clean = file_get_contents($css);
    $css_ok = $css_result === true && is_string($css_clean) && strpos($css_clean, "example.invalid") === false;

    file_put_contents($loop, "<svg xmlns=\"http://www.w3.org/2000/svg\"><g id=\"ping\"><use HrEf=\"#pong\"/></g><g id=\"pong\"><use HREF=\"#ping\"/></g></svg>");
    $loop_result = \CB\Core\MediaFormats\Svg\Sanitizer::sanitize_file($loop);
    $loop_clean = file_get_contents($loop);
    $loop_ok = $loop_result === true && is_string($loop_clean) && preg_match("/<use\\b/i", $loop_clean) === 0;

    $all_ok = $version === "1.0.0" && $dtd_ok && $css_ok && $loop_ok;
    echo $all_ok ? "PASS" : "FAIL";
} finally {
    @unlink($dtd);
    @unlink($css);
    @unlink($loop);
}
')"

  if [[ "$result" != "PASS" ]]; then
    echo "[H] Packaged SVG sanitizer runtime failed: $result" >&2
    exit 1
  fi

  echo "[H] packaged SVG sanitizer runtime PASS: 1.0.0"
}

FRESH_SITE="$TMP_ROOT/fresh"
prepare_site "$FRESH_SITE" 'cbhfresh_' 'http://core-blueprint-release-fresh.test' 'Core Blueprint Release Fresh'
wp_cli "$FRESH_SITE" plugin install "$CANDIDATE_ZIP" --activate
assert_active_version "$FRESH_SITE" "$CANDIDATE_VERSION"
assert_packaged_svg_sanitizer "$FRESH_SITE"

operator_exists="$(wp_cli "$FRESH_SITE" eval 'echo get_role( "cb_operator" ) ? "yes" : "no";')"
if [[ "$operator_exists" != "yes" ]]; then
  echo "[H] Fresh package activation did not create cb_operator." >&2
  exit 1
fi

assert_clean_debug_log "$FRESH_SITE"
echo "[H] fresh release ZIP install/activation PASS: $CANDIDATE_VERSION"

UPDATE_SITE="$TMP_ROOT/update"
prepare_site "$UPDATE_SITE" 'cbhupdate_' 'http://core-blueprint-release-update.test' 'Core Blueprint Release Update'
wp_cli "$UPDATE_SITE" plugin install "$PREVIOUS_ZIP" --activate
assert_active_version "$UPDATE_SITE" "$PREVIOUS_VERSION"
assert_clean_debug_log "$UPDATE_SITE"

REPORT_SENTINEL_ID="$(wp_cli "$UPDATE_SITE" eval 'echo (int) \\CB\\Core\\Reports\\Storage::save([
    "period_start" => "2026-09-01",
    "period_end" => "2026-09-30",
    "generated_by" => 0,
    "report_data" => [
        "snapshot_version" => \\CB\\Core\\Reports\\MaintenanceAggregator::SNAPSHOT_VERSION,
        "sentinel" => "release-update-preserve-me",
    ],
    "status" => "generated",
]);')"
if [[ ! "$REPORT_SENTINEL_ID" =~ ^[1-9][0-9]*$ ]]; then
  echo "[H] Previous RC could not seed Reports preservation sentinel: $REPORT_SENTINEL_ID" >&2
  exit 1
fi

wp_cli "$UPDATE_SITE" plugin install "$CANDIDATE_ZIP" --force
assert_active_version "$UPDATE_SITE" "$CANDIDATE_VERSION"

report_preserved="$(wp_cli "$UPDATE_SITE" eval "echo is_array(\\CB\\Core\\Reports\\Storage::find($REPORT_SENTINEL_ID)) && (\\CB\\Core\\Reports\\Storage::find($REPORT_SENTINEL_ID)['report_data']['sentinel'] ?? '') === 'release-update-preserve-me' ? 'yes' : 'no';")"
if [[ "$report_preserved" != "yes" ]]; then
  echo "[H] Existing Maintenance Report was not preserved across release update." >&2
  exit 1
fi
echo "[H] release update Reports preservation PASS: id=$REPORT_SENTINEL_ID"

assert_clean_debug_log "$UPDATE_SITE"

echo "[H] update-over-current-RC PASS: $PREVIOUS_VERSION -> $CANDIDATE_VERSION"
echo "[H] release install/update scenario PASS"
