#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
REQUEST="$ROOT/tests/performance/request.php"
TRACE_PREPEND="$ROOT/tests/performance/query-trace-prepend.php"
RESULTS_DIR="${CB_PERFORMANCE_RESULTS_DIR:-${TMPDIR:-/tmp}/cb-performance-baseline}"
TRACE_DIR="$RESULTS_DIR/query-traces"
AUTH_GUARD="${WP_CORE_DIR:-}/wp-content/mu-plugins/cb-f2-auth-guard.php"

: "${WP_CORE_DIR:?WP_CORE_DIR is required}"
: "${CB_PLUGIN_FILE:?CB_PLUGIN_FILE is required}"
: "${WP_DB_NAME:?WP_DB_NAME is required}"
: "${WP_DB_USER:?WP_DB_USER is required}"
: "${WP_DB_PASSWORD:?WP_DB_PASSWORD is required}"
: "${WP_DB_HOST:?WP_DB_HOST is required}"

export CB_PERFORMANCE_TABLE_PREFIX="${CB_PERFORMANCE_TABLE_PREFIX:-cbperf_}"

rm -rf -- "$RESULTS_DIR"
mkdir -p -- "$RESULTS_DIR" "$TRACE_DIR"

cleanup() {
  rm -f -- "$AUTH_GUARD" 2>/dev/null || true
  php "$REQUEST" cleanup >/dev/null 2>&1 || true
}
trap cleanup EXIT

write_auth_guard() {
  mkdir -p -- "$(dirname "$AUTH_GUARD")"
  cat > "$AUTH_GUARD" <<'PHP'
<?php
if ( '1' === getenv( 'CB_PERFORMANCE_AUTHENTICATED' ) ) {
    add_action( 'plugins_loaded', static function (): void {
        $admin = get_user_by( 'login', 'cbadmin' );
        if ( ! $admin instanceof WP_User ) {
            throw new RuntimeException( 'F2 authenticated control could not resolve cbadmin.' );
        }
        wp_set_current_user( (int) $admin->ID );
    }, PHP_INT_MAX );

    add_action( 'wp_enqueue_scripts', static function (): void {
        if ( ! is_user_logged_in() ) {
            throw new RuntimeException( 'F2 authenticated frontend request lost its user session.' );
        }
        if ( '1' === getenv( 'CB_PERFORMANCE_REQUIRE_HUD_CAP' ) && ! current_user_can( 'cb_core_hud_use' ) ) {
            throw new RuntimeException( 'F2 operator frontend request is missing cb_core_hud_use.' );
        }
    }, PHP_INT_MAX );
}

if ( '1' === getenv( 'CB_PERFORMANCE_QUERY_TRACE' ) ) {
    $cb_f3_mark_phase = static function ( string $phase ): void {
        global $wpdb;
        if ( $wpdb instanceof wpdb ) {
            if ( ! isset( $GLOBALS['cb_f3_phase_marks'] ) || ! is_array( $GLOBALS['cb_f3_phase_marks'] ) ) {
                $GLOBALS['cb_f3_phase_marks'] = [];
            }
            $GLOBALS['cb_f3_phase_marks'][ $phase ] = (int) $wpdb->num_queries;
        }
    };

    add_action( 'wp_loaded', static function () use ( $cb_f3_mark_phase ): void {
        $cb_f3_mark_phase( 'wp_loaded' );
    }, PHP_INT_MAX );

    add_action( 'admin_init', static function () use ( $cb_f3_mark_phase ): void {
        $cb_f3_mark_phase( 'admin_init' );
    }, PHP_INT_MAX );

    add_action( 'admin_menu', static function () use ( $cb_f3_mark_phase ): void {
        $cb_f3_mark_phase( 'admin_menu' );
    }, PHP_INT_MAX );

    add_action( 'admin_enqueue_scripts', static function () use ( $cb_f3_mark_phase ): void {
        $cb_f3_mark_phase( 'admin_load_enqueue' );
    }, PHP_INT_MAX );

    add_action( 'wp', static function () use ( $cb_f3_mark_phase ): void {
        $cb_f3_mark_phase( 'wp' );
    }, PHP_INT_MAX );

    add_action( 'wp_enqueue_scripts', static function () use ( $cb_f3_mark_phase ): void {
        $cb_f3_mark_phase( 'enqueue' );

        if ( '1' !== getenv( 'CB_PERFORMANCE_RENDER_FOOTER' ) ) {
            return;
        }

        ob_start();
        try {
            do_action( 'wp_footer' );
        } finally {
            ob_end_clean();
        }
        $cb_f3_mark_phase( 'render' );
    }, PHP_INT_MAX );
}
PHP
}

profile_as() {
  local source_stage="$1"
  local output_scenario="$2"
  local plugin_active="$3"
  local authenticated="$4"
  local require_hud_cap="${5:-0}"
  local query_trace="${6:-0}"
  local render_footer="${7:-0}"
  local output_file="$RESULTS_DIR/${output_scenario}.json"
  local trace_file="$TRACE_DIR/${output_scenario}.json"

  echo "[F3A] profiling: $output_scenario"

  if [[ "$query_trace" == "1" ]]; then
    CB_PERFORMANCE_AUTHENTICATED="$authenticated" \
    CB_PERFORMANCE_REQUIRE_HUD_CAP="$require_hud_cap" \
    CB_PERFORMANCE_QUERY_TRACE="1" \
    CB_PERFORMANCE_QUERY_TRACE_FILE="$trace_file" \
    CB_PERFORMANCE_RENDER_FOOTER="$render_footer" \
      php -d "auto_prepend_file=$TRACE_PREPEND" "$REQUEST" "$source_stage" > "$output_file"
  else
    CB_PERFORMANCE_AUTHENTICATED="$authenticated" \
    CB_PERFORMANCE_REQUIRE_HUD_CAP="$require_hud_cap" \
    CB_PERFORMANCE_QUERY_TRACE="0" \
    CB_PERFORMANCE_RENDER_FOOTER="0" \
      php "$REQUEST" "$source_stage" > "$output_file"
  fi

  php -r '
    $path = $argv[1];
    $scenario = $argv[2];
    $pluginActive = "1" === $argv[3];
    $authenticated = "1" === $argv[4];
    $queryTrace = "1" === $argv[5];
    $rendered = "1" === $argv[6];
    $raw = file_get_contents($path);
    $data = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || 1 !== ($data["schema"] ?? null) || !isset($data["metrics"])) {
        fwrite(STDERR, "Invalid F3A source performance record: {$path}\n");
        exit(1);
    }
    $data["schema"] = 2;
    $data["scenario"] = $scenario;
    $data["context"] = [
        "plugin_active" => $pluginActive,
        "authenticated" => $authenticated,
        "query_trace" => $queryTrace,
        "rendered" => $rendered,
    ];
    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
    );
  ' "$output_file" "$output_scenario" "$plugin_active" "$authenticated" "$query_trace" "$render_footer"

  if [[ "$query_trace" == "1" ]]; then
    php -r '
      $path = $argv[1];
      if (!is_file($path)) {
          fwrite(STDERR, "Missing F3A query trace: {$path}\n");
          exit(1);
      }
      $trace = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
      if (!is_array($trace) || 1 !== ($trace["schema"] ?? null) || !isset($trace["phase_marks"], $trace["entries"])) {
          fwrite(STDERR, "Invalid F3A query trace: {$path}\n");
          exit(1);
      }
    ' "$trace_file"
  fi
}

echo "[F3A] preparing isolated performance site"
php "$REQUEST" install
write_auth_guard

# WordPress-only controls. Base is present on disk but deliberately inactive.
profile_as frontend control_frontend 0 0
profile_as frontend control_operator_frontend 0 1
profile_as frontend control_operator_frontend_rendered 0 1 0 1 1
profile_as admin control_admin 0 1 0 1

php "$REQUEST" activate

# Capture the first authenticated admin request separately so one-time
# activation/schema/policy reconciliation cost can be distinguished from the
# later steady-state generic admin request.
profile_as admin admin_first_request 1 1 0 1

# Base-enabled steady-state comparison requests.
profile_as frontend frontend 1 0
profile_as frontend operator_frontend 1 1 1
profile_as frontend operator_frontend_rendered 1 1 1 1 1
profile_as admin admin 1 1 0 1
profile_as dashboard dashboard 1 1
profile_as logs logs 1 1
profile_as reports reports 1 1
profile_as safeguards safeguards 1 1

# Verify that canonical disabled modules do not continue to execute their
# active runtime paths on an otherwise identical authenticated admin request.
php "$REQUEST" disable_modules
profile_as admin admin_modules_disabled 1 1 0 1

php -r '
  $dir = rtrim($argv[1], "/\\");
  $traceDir = $dir . "/query-traces";
  $files = glob($dir . "/*.json") ?: [];
  sort($files);
  $records = [];
  $byScenario = [];
  foreach ($files as $file) {
      if (basename($file) === "baseline.json") {
          continue;
      }
      $record = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
      if (!is_array($record) || 2 !== ($record["schema"] ?? null) || !isset($record["scenario"], $record["metrics"], $record["context"])) {
          throw new RuntimeException("Invalid F3A performance record: {$file}");
      }
      $records[] = $record;
      $byScenario[(string) $record["scenario"]] = $record;
  }

  $pairs = [
      "anonymous_frontend"        => [ "control" => "control_frontend", "base" => "frontend" ],
      "operator_frontend"         => [ "control" => "control_operator_frontend", "base" => "operator_frontend" ],
      "operator_frontend_rendered"=> [ "control" => "control_operator_frontend_rendered", "base" => "operator_frontend_rendered" ],
      "generic_admin"             => [ "control" => "control_admin", "base" => "admin" ],
      "generic_admin_modules_disabled" => [ "control" => "control_admin", "base" => "admin_modules_disabled" ],
  ];

  $metricPaths = [
      "memory_usage_bytes"       => [ "metrics", "memory", "usage_bytes" ],
      "memory_peak_bytes"        => [ "metrics", "memory", "peak_bytes" ],
      "memory_peak_delta_bytes"  => [ "metrics", "memory", "peak_delta_bytes" ],
      "database_queries"         => [ "metrics", "database", "queries" ],
      "style_count"              => [ "metrics", "assets", "style_count" ],
      "script_count"             => [ "metrics", "assets", "script_count" ],
      "script_module_count"      => [ "metrics", "assets", "script_module_count" ],
      "cb_local_bytes"           => [ "metrics", "assets", "cb_local_bytes" ],
      "autoload_total_count"     => [ "metrics", "autoload", "total_count" ],
      "autoload_total_bytes"     => [ "metrics", "autoload", "total_bytes" ],
      "autoload_cb_count"        => [ "metrics", "autoload", "cb_count" ],
      "autoload_cb_bytes"        => [ "metrics", "autoload", "cb_bytes" ],
      "cron_total_events"        => [ "metrics", "cron", "total_events" ],
      "cron_cb_events"           => [ "metrics", "cron", "cb_events" ],
  ];

  $value = static function (array $record, array $path): int {
      $cursor = $record;
      foreach ($path as $key) {
          if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
              throw new RuntimeException("Missing metric path: " . implode(".", $path));
          }
          $cursor = $cursor[$key];
      }
      if (!is_int($cursor) && !is_float($cursor)) {
          throw new RuntimeException("Non-numeric metric path: " . implode(".", $path));
      }
      return (int) $cursor;
  };

  $comparisons = [];
  foreach ($pairs as $name => $pair) {
      $controlName = $pair["control"];
      $baseName = $pair["base"];
      if (!isset($byScenario[$controlName], $byScenario[$baseName])) {
          throw new RuntimeException("Missing F3A attribution pair: {$name}");
      }
      if (true === ($byScenario[$controlName]["context"]["plugin_active"] ?? null)) {
          throw new RuntimeException("F3A control unexpectedly marks Base active: {$controlName}");
      }
      if (true !== ($byScenario[$baseName]["context"]["plugin_active"] ?? null)) {
          throw new RuntimeException("F3A Base scenario unexpectedly marks Base inactive: {$baseName}");
      }

      $delta = [];
      foreach ($metricPaths as $metric => $path) {
          $delta[$metric] = $value($byScenario[$baseName], $path) - $value($byScenario[$controlName], $path);
      }
      $comparisons[$name] = [
          "control_scenario" => $controlName,
          "base_scenario" => $baseName,
          "delta" => $delta,
      ];
  }

  $loadTrace = static function (string $path): array {
      $trace = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
      if (!is_array($trace) || 1 !== ($trace["schema"] ?? null) || !isset($trace["entries"]) || !is_array($trace["entries"])) {
          throw new RuntimeException("Invalid F3A query trace: {$path}");
      }
      return $trace;
  };

  $phaseCounts = static function (array $entries): array {
      $counts = [
          "bootstrap" => 0,
          "wp" => 0,
          "enqueue" => 0,
          "render" => 0,
          "admin_init" => 0,
          "admin_menu" => 0,
          "admin_load_enqueue" => 0,
      ];
      foreach ($entries as $entry) {
          if (!is_array($entry)) {
              continue;
          }
          $phase = (string) ($entry["phase"] ?? "");
          if (array_key_exists($phase, $counts)) {
              $counts[$phase]++;
          }
      }
      return $counts;
  };

  $queryKey = static function (array $entry): string {
      return (string) ($entry["phase"] ?? "") . "\n" . (string) ($entry["sql"] ?? "");
  };

  $extraQueries = static function (array $sourceEntries, array $subtractEntries) use ($queryKey): array {
      $available = [];
      foreach ($subtractEntries as $entry) {
          if (!is_array($entry)) {
              continue;
          }
          $key = $queryKey($entry);
          $available[$key] = ($available[$key] ?? 0) + 1;
      }

      $extra = [];
      foreach ($sourceEntries as $entry) {
          if (!is_array($entry)) {
              continue;
          }
          $key = $queryKey($entry);
          if (($available[$key] ?? 0) > 0) {
              $available[$key]--;
              continue;
          }
          $extra[] = $entry;
      }
      return $extra;
  };

  $buildAttribution = static function (
      string $controlPath,
      string $basePath
  ) use ($loadTrace, $phaseCounts, $extraQueries): array {
      if (!is_file($controlPath) || !is_file($basePath)) {
          throw new RuntimeException("F3A query attribution trace is missing.");
      }

      $controlTrace = $loadTrace($controlPath);
      $baseTrace = $loadTrace($basePath);
      $controlPhaseCounts = $phaseCounts($controlTrace["entries"]);
      $basePhaseCounts = $phaseCounts($baseTrace["entries"]);
      $phaseDelta = [];
      foreach ($basePhaseCounts as $phase => $count) {
          $phaseDelta[$phase] = $count - ($controlPhaseCounts[$phase] ?? 0);
      }

      return [
          "phase_counts" => [
              "control" => $controlPhaseCounts,
              "base" => $basePhaseCounts,
              "delta" => $phaseDelta,
          ],
          "base_extra_queries" => $extraQueries($baseTrace["entries"], $controlTrace["entries"]),
          "control_only_queries" => $extraQueries($controlTrace["entries"], $baseTrace["entries"]),
      ];
  };

  $frontendAttribution = $buildAttribution(
      $traceDir . "/control_operator_frontend_rendered.json",
      $traceDir . "/operator_frontend_rendered.json"
  );
  $queryAttribution = array_merge(
      [
          "control_trace" => "query-traces/control_operator_frontend_rendered.json",
          "base_trace" => "query-traces/operator_frontend_rendered.json",
      ],
      $frontendAttribution
  );

  $adminAttribution = [
      "steady_state" => array_merge(
          [
              "control_trace" => "query-traces/control_admin.json",
              "base_trace" => "query-traces/admin.json",
          ],
          $buildAttribution(
              $traceDir . "/control_admin.json",
              $traceDir . "/admin.json"
          )
      ),
      "first_request" => array_merge(
          [
              "control_trace" => "query-traces/control_admin.json",
              "base_trace" => "query-traces/admin_first_request.json",
          ],
          $buildAttribution(
              $traceDir . "/control_admin.json",
              $traceDir . "/admin_first_request.json"
          )
      ),
      "modules_disabled" => array_merge(
          [
              "control_trace" => "query-traces/control_admin.json",
              "base_trace" => "query-traces/admin_modules_disabled.json",
          ],
          $buildAttribution(
              $traceDir . "/control_admin.json",
              $traceDir . "/admin_modules_disabled.json"
          )
      ),
  ];

  $out = [
      "schema" => 3,
      "purpose" => "BASE-V1-F3A performance + query-source attribution; observation only, no numeric gates",
      "records" => $records,
      "comparisons" => $comparisons,
      "query_attribution" => $queryAttribution,
      "admin_query_attribution" => $adminAttribution,
  ];
  file_put_contents(
      $dir . "/baseline.json",
      json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
  );
' "$RESULTS_DIR"

echo "[F3A] performance + query attribution JSON"
cat "$RESULTS_DIR/baseline.json"
echo "[F3A] performance + query attribution PASS (measurement integrity only; no numeric thresholds)"
