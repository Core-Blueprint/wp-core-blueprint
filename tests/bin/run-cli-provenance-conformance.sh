#!/usr/bin/env bash
set -euo pipefail

WP_VERSION="${1:-}"
[[ "$WP_VERSION" =~ ^7\.(0|1)$ ]] || { echo "Usage: $0 <7.0|7.1>" >&2; exit 64; }
: "${WP_CORE_DIR:?WP_CORE_DIR is required}"
: "${CB_WP_CLI_PHAR:?CB_WP_CLI_PHAR is required}"

wp_cli(){ php "$CB_WP_CLI_PHAR" --path="$WP_CORE_DIR" --no-color "$@"; }
wp_cli_eval_args(){ local php_code="$1"; shift; printf '<?php\n%s\n' "$php_code" | wp_cli eval-file - "$@"; }
fail(){ echo "[B2] FAIL: $*" >&2; exit 1; }
contains(){ grep -Fq "$2" <<<"$1" || { printf '%s\n' "$1" >&2; fail "$3 (missing: $2)"; }; }
eq(){ [[ "$1" == "$2" ]] || fail "$3 (expected '$2', got '$1')"; }
audit_count(){ wp_cli_eval_args '$q=\CoreBlueprint\Core\Log\AuditLog::query(["event_type"=>$args[0],"per_page"=>1]);echo (int)$q["total"];' "$1"; }
audit_context(){ wp_cli_eval_args '$q=\CoreBlueprint\Core\Log\AuditLog::query(["event_type"=>$args[0],"per_page"=>1]);$r=$q["rows"][0]??null;echo $r?wp_json_encode($r->context_decoded??[]):"";' "$1"; }
auth(){ wp_cli_eval_args '$u=get_userdata((int)$args[0]);$e=time()+3600;$t=WP_Session_Tokens::get_instance($u->ID)->create($e);$c=wp_generate_auth_cookie($u->ID,$e,"logged_in",$t);$_COOKIE[LOGGED_IN_COOKIE]=$c;wp_set_current_user($u->ID);echo LOGGED_IN_COOKIE,"\n",$c,"\n",wp_create_nonce("wp_rest"),"\n";' "$1"; }

pid=""
cleanup(){
	if [[ -n "$pid" ]]; then kill "$pid" >/dev/null 2>&1 || true; fi
	wp_cli config delete DISABLE_WP_CRON >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "[B2] Starting C2-B2 real WP-CLI/browser provenance conformance on WordPress $WP_VERSION"
wp_cli plugin is-active core-blueprint >/dev/null 2>&1 || fail 'Core Blueprint is not active from B0/B1'
wp_cli help cb operator add >/dev/null
wp_cli help cb permissions hide-page >/dev/null
wp_cli help cb logs prune >/dev/null
wp_cli help cb scan run >/dev/null

target_id="$(wp_cli user create cb-b2-operator cb-b2-operator@example.test --role=subscriber --user_pass=cb-b2-operator-pass --porcelain)"
spoof_id="$(wp_cli user create cb-b2-spoof cb-b2-spoof@example.test --role=subscriber --user_pass=cb-b2-spoof-pass --porcelain)"

add="$(wp_cli cb operator add "$target_id")"
contains "$add" 'promoted to CB Operator' 'Terminal operator add failed'
contains "$(audit_context permissions_operator_added)" '"by":"cli"' 'Terminal operator add was mislabeled'

wp_cli cb permissions hide-page >/dev/null
contains "$(audit_context permissions_hide_changed)" '"by":"cli"' 'Terminal hide-page specific audit was mislabeled'
contains "$(audit_context settings_changed)" '"actor":"cli"' 'Terminal hide-page settings audit was mislabeled'
wp_cli cb permissions show-page >/dev/null
contains "$(audit_context permissions_hide_changed)" '"by":"cli"' 'Terminal show-page specific audit was mislabeled'
contains "$(audit_context settings_changed)" '"actor":"cli"' 'Terminal show-page settings audit was mislabeled'
echo "[B2] Terminal shared-command provenance PASS"

prune0="$(audit_count logs_pruned)"
if wp_cli cb logs prune --category=not-a-category >/tmp/cb-b2-prune-invalid.txt 2>&1; then
	cat /tmp/cb-b2-prune-invalid.txt >&2
	fail 'Invalid terminal logs prune unexpectedly succeeded'
fi
eq "$(audit_count logs_pruned)" "$prune0" 'Rejected terminal logs prune emitted success provenance'
wp_cli cb logs prune --category=general >/dev/null
eq "$(audit_count logs_pruned)" "$((prune0+1))" 'Successful terminal logs prune did not emit exactly one durable provenance event'
prune_context="$(audit_context logs_pruned)"
contains "$prune_context" '"category":"general"' 'Terminal logs prune audit omitted category'
contains "$prune_context" '"via":"cli"' 'Terminal logs prune audit used wrong origin'
echo "[B2] Manual logs prune durable provenance PASS"

# Prove the same shared Hide/Show production path remains browser Console provenance.
wp_cli cb permissions hide-page >/dev/null
port="${CB_B2_HTTP_PORT:-8100}"
site="http://127.0.0.1:$port"
wp_cli option update home "$site" >/dev/null
wp_cli option update siteurl "$site" >/dev/null
log="${RUNNER_TEMP:-/tmp}/cb-b2-http-$WP_VERSION.log"

start_http_server(){
	if [[ -n "$pid" ]] && kill -0 "$pid" >/dev/null 2>&1; then
		return 0
	fi
	php -S "127.0.0.1:$port" -t "$WP_CORE_DIR" >>"$log" 2>&1 &
	pid=$!
}

wait_for_http_server(){
	local attempt
	for attempt in {1..30}; do
		if command curl -sS -o /dev/null "$site/wp-login.php"; then
			return 0
		fi
		if ! kill -0 "$pid" >/dev/null 2>&1; then
			return 1
		fi
		sleep .2
	done
	return 1
}

restart_http_server(){
	if [[ -n "$pid" ]]; then
		kill "$pid" >/dev/null 2>&1 || true
		wait "$pid" >/dev/null 2>&1 || true
	fi
	pid=""
	start_http_server
	if ! wait_for_http_server; then
		cat "$log" >&2
		fail 'Local WordPress HTTP server failed to restart'
	fi
	echo "[B2] Local WordPress HTTP server restarted after transient runner failure" >&2
}

B2_HTTP_CODE=""
browser_request(){
	local attempt=1
	local max_attempts="${CB_B2_HTTP_MAX_ATTEMPTS:-4}"
	local exit_code=0
	local result=""

	B2_HTTP_CODE=""
	while true; do
		if [[ -z "$pid" ]] || ! kill -0 "$pid" >/dev/null 2>&1; then
			restart_http_server
		fi

		if result="$(command curl "$@")"; then
			B2_HTTP_CODE="$result"
			return 0
		else
			exit_code=$?
		fi

		if (( exit_code != 7 && exit_code != 52 )); then
			return "$exit_code"
		fi

		if (( attempt >= max_attempts )); then
			cat "$log" >&2
			echo "[B2] Transient localhost transport failure persisted after ${attempt} attempts (exit ${exit_code})" >&2
			return "$exit_code"
		fi

		if ! kill -0 "$pid" >/dev/null 2>&1; then
			restart_http_server
		else
			sleep .25
		fi
		attempt=$((attempt + 1))
	done
}

start_http_server
if ! wait_for_http_server; then
	cat "$log" >&2
	fail 'Local WordPress HTTP server failed'
fi

mapfile -t browser < <(auth "$target_id")
[[ ${#browser[@]} -ge 3 ]] || fail 'Browser Console auth fixture failed'
browser_cookie="${browser[0]}=${browser[1]}"
browser_rest="${browser[2]}"
browser_request -sS -o /tmp/cb-b2-console-show.json -w '%{http_code}' -X POST -H "Cookie: $browser_cookie" -H "X-WP-Nonce: $browser_rest" -H 'Content-Type: application/json' --data '{"id":"cb-permissions-show","args":{}}' "$site/?rest_route=/core-blueprint/v1/console/run"
code="$B2_HTTP_CODE"
eq "$code" 200 'Browser Console show-page failed'
contains "$(cat /tmp/cb-b2-console-show.json)" '"status":"success"' 'Browser Console show-page did not complete successfully'
contains "$(audit_context permissions_hide_changed)" '"by":"console"' 'Browser Console specific audit lost console provenance'
contains "$(audit_context settings_changed)" '"actor":"console"' 'Browser Console settings audit lost console provenance'
contains "$(audit_context console_executed)" '"via":"console"' 'Browser Console execution audit lost console provenance'
echo "[B2] Browser Console provenance isolation PASS"

# Keep async jobs persisted long enough to inspect their canonical actor field.
wp_cli eval '\CoreBlueprint\Core\Integrity\State::set_enabled(true,"c2b2-conformance");' >/dev/null
wp_cli config set DISABLE_WP_CRON true --raw >/dev/null

spoof_run="$(wp_cli cb scan run --user="$spoof_id")"
contains "$spoof_run" 'operator: server CLI' 'Terminal Scanner did not identify the trusted server CLI context'
cli_actor="$(wp_cli eval '$j=\CoreBlueprint\Core\Integrity\Scanner\ScanJobRepository::get();echo is_array($j)?(int)($j["started_by_user_id"]??-1):-1;')"
eq "$cli_actor" 0 'Terminal Scanner persisted caller-selected WordPress user attribution'
eq "$(wp_cli eval '$j=\CoreBlueprint\Core\Integrity\Scanner\ScanJobRepository::get();echo is_array($j)?(string)($j["source"]??""):"";')" manual 'Terminal Scanner source contract drifted'
wp_cli eval '\CoreBlueprint\Core\Integrity\Scanner\ScanJobRunner::cancel_active();' >/dev/null
eq "$(wp_cli eval 'echo null===\CoreBlueprint\Core\Integrity\Scanner\ScanJobRepository::get()?"empty":"present";')" empty 'Terminal Scanner fixture cleanup failed'

browser_request -sS -o /tmp/cb-b2-console-scan.json -w '%{http_code}' -X POST -H "Cookie: $browser_cookie" -H "X-WP-Nonce: $browser_rest" -H 'Content-Type: application/json' --data "{\"id\":\"cb-scan-run\",\"args\":{\"user\":\"$target_id\"}}" "$site/?rest_route=/core-blueprint/v1/console/run"
code="$B2_HTTP_CODE"
eq "$code" 200 'Browser Console Scanner failed'
contains "$(cat /tmp/cb-b2-console-scan.json)" '"status":"success"' 'Browser Console Scanner did not schedule successfully'
browser_actor="$(wp_cli eval '$j=\CoreBlueprint\Core\Integrity\Scanner\ScanJobRepository::get();echo is_array($j)?(int)($j["started_by_user_id"]??-1):-1;')"
eq "$browser_actor" "$target_id" 'Browser Console Scanner lost its authenticated WordPress operator attribution'
wp_cli eval '\CoreBlueprint\Core\Integrity\Scanner\ScanJobRunner::cancel_active();' >/dev/null
wp_cli config delete DISABLE_WP_CRON >/dev/null

echo "[B2] Scanner CLI/browser attribution isolation PASS"

remove="$(wp_cli cb operator remove "$target_id" --force)"
contains "$remove" 'demoted from CB Operator' 'Terminal operator cleanup failed'
contains "$(audit_context permissions_operator_removed)" '"by":"cli"' 'Terminal operator remove was mislabeled'
contains "$(wp_cli cb permissions status)" 'Operator count:           0' 'B2 cleanup did not restore zero operators'

echo "[B2] C2-B2 CLI provenance / Scanner attribution conformance PASS"
