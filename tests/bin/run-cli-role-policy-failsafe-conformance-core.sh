#!/usr/bin/env bash
set -euo pipefail

WP_VERSION="${1:-}"
[[ "$WP_VERSION" =~ ^7\.(0|1)$ ]] || { echo "Usage: $0 <7.0|7.1>" >&2; exit 64; }
: "${WP_CORE_DIR:?WP_CORE_DIR is required}"
: "${CB_WP_CLI_PHAR:?CB_WP_CLI_PHAR is required}"

wp_cli(){ php "$CB_WP_CLI_PHAR" --path="$WP_CORE_DIR" --no-color "$@"; }
wp_cli_eval_args(){ local php_code="$1"; shift; printf '<?php\n%s\n' "$php_code" | wp_cli eval-file - "$@"; }
fail(){ echo "[B1] FAIL: $*" >&2; exit 1; }
contains(){ grep -Fq "$2" <<<"$1" || { printf '%s\n' "$1" >&2; fail "$3 (missing: $2)"; }; }
eq(){ [[ "$1" == "$2" ]] || fail "$3 (expected '$2', got '$1')"; }
role_hash(){ wp_cli_eval_args '$r=get_role($args[0]);$c=$r?$r->capabilities:[];ksort($c);echo hash("sha256",wp_json_encode($c));' "$1"; }
user_hash(){ wp_cli_eval_args '$u=get_userdata((int)$args[0]);$m=get_user_meta($u->ID);ksort($m);foreach($m as &$v){sort($v,SORT_STRING);}unset($v);$r=$u->roles;sort($r,SORT_STRING);echo hash("sha256",wp_json_encode([$r,$m]));' "$1"; }
audit_count(){ wp_cli_eval_args '$q=\CoreBlueprint\Core\Log\AuditLog::query(["event_type"=>$args[0],"per_page"=>1]);echo (int)$q["total"];' "$1"; }
auth(){ wp_cli_eval_args '$u=get_userdata((int)$args[0]);$e=time()+3600;$t=WP_Session_Tokens::get_instance($u->ID)->create($e);$c=wp_generate_auth_cookie($u->ID,$e,"logged_in",$t);$_COOKIE[LOGGED_IN_COOKIE]=$c;wp_set_current_user($u->ID);echo LOGGED_IN_COOKIE,"\n",$c,"\n",wp_create_nonce("wp_rest"),"\n",wp_create_nonce("cb_core_admin"),"\n";' "$1"; }

pid=""
cleanup(){
	if [[ -n "$pid" ]]; then
		kill "$pid" >/dev/null 2>&1 || true
	fi
}
trap cleanup EXIT

echo "[B1] Starting C2-B1 real WP-CLI/browser conformance on WordPress $WP_VERSION"
wp_cli plugin is-active core-blueprint >/dev/null 2>&1 || fail 'Core Blueprint is not active from B0'
wp_cli help cb operator add >/dev/null
wp_cli help cb permissions repair-role-policy >/dev/null
wp_cli help cb failsafe disable >/dev/null
wp_cli help cb failsafe rotate-token >/dev/null
echo "[B1] Real WP-CLI mutating command registration PASS"

admin_id="$(wp_cli user get cb-cli-admin --field=ID)"
unrelated_id="$(wp_cli user create cb-b1-unrelated cb-b1-unrelated@example.test --role=subscriber --user_pass=cb-b1-unrelated-pass --porcelain)"
candidate_id="$(wp_cli user create cb-b1-operator cb-b1-operator@example.test --role=subscriber --user_pass=cb-b1-operator-pass --porcelain)"
wp_cli role create cb_b1_unrelated 'CB B1 Unrelated' >/dev/null
wp_cli user set-role "$unrelated_id" cb_b1_unrelated >/dev/null
unrelated_role="$(role_hash cb_b1_unrelated)"; unrelated_user="$(user_hash "$unrelated_id")"

status="$(wp_cli cb permissions status)"
contains "$status" 'Role Policy canonical:    YES' 'Fresh B0 site is not canonical'
contains "$status" 'Operator count:           0' 'Fresh B0 site is not zero-operator'
add="$(wp_cli cb operator add "$candidate_id")"
contains "$add" 'promoted to CB Operator' 'Zero-operator terminal recovery failed'
contains "$(wp_cli cb permissions status)" 'Operator count:           1' 'Operator add did not persist'
eq "$(role_hash cb_b1_unrelated)" "$unrelated_role" 'Operator add changed unrelated role'
eq "$(user_hash "$unrelated_id")" "$unrelated_user" 'Operator add changed unrelated user'
echo "[B1] Zero-operator terminal recovery PASS"

# Established-site Role Policy drift: missing marker + missing cap + stored meta-cap.
wp_cli eval 'delete_option("cb_core_role_policy_schema_version");' >/dev/null
wp_cli cap remove cb_operator cb_manage_roles >/dev/null
wp_cli role create cb_b1_meta_fixture 'CB B1 Meta Fixture' >/dev/null
wp_cli cap add cb_b1_meta_fixture cb_replace_media >/dev/null
eq "$(wp_cli eval 'echo get_option("cb_core_role_policy_schema_version","missing");')" missing 'Normal request silently restored missing schema'
operator_trust="$(user_hash "$candidate_id")"; admin_trust="$(user_hash "$admin_id")"; trust_schema="$(wp_cli option get cb_core_trust_schema_version)"
status="$(wp_cli cb permissions status)"
contains "$status" 'Role Policy schema:       MISSING/INVALID' 'Status missed missing schema'
contains "$status" 'operator_missing_cap:cb_manage_roles' 'Status missed missing operator cap'
contains "$status" 'role_stored_meta_cap:cb_b1_meta_fixture:cb_replace_media' 'Status missed stored Base meta-cap'
eq "$(wp_cli eval '$r=get_role("cb_operator");echo $r->has_cap("cb_manage_roles")?"yes":"no";')" no 'Status silently healed operator cap'
eq "$(wp_cli eval 'echo get_option("cb_core_role_policy_schema_version","missing");')" missing 'Status silently healed schema marker'

repair="$(wp_cli cb permissions repair-role-policy)"
contains "$repair" 'Canonical Core Blueprint role definitions and capabilities were repaired.' 'Explicit repair failed'
contains "$(wp_cli cb permissions status)" 'Role Policy canonical:    YES' 'Repair did not restore canonical state'
eq "$(wp_cli eval '$r=get_role("cb_b1_meta_fixture");echo $r->has_cap("cb_replace_media")?"yes":"no";')" no 'Repair did not remove stored Base meta-cap'
eq "$(user_hash "$candidate_id")" "$operator_trust" 'Repair changed operator assignment/approval/review/fingerprint metadata'
eq "$(user_hash "$admin_id")" "$admin_trust" 'Repair changed Administrator assignment/approval/review metadata'
eq "$(wp_cli option get cb_core_trust_schema_version)" "$trust_schema" 'Repair changed Trust Schema'
eq "$(role_hash cb_b1_unrelated)" "$unrelated_role" 'Repair changed unrelated role'
eq "$(user_hash "$unrelated_id")" "$unrelated_user" 'Repair changed unrelated user'
policy1="$(wp_cli eval '$o=[];foreach(["cb_operator","administrator"] as $s){$r=get_role($s);$c=$r?$r->capabilities:[];ksort($c);$o[$s]=$c;}ksort($o);echo hash("sha256",wp_json_encode($o));')"
repair2="$(wp_cli cb permissions repair-role-policy)"; policy2="$(wp_cli eval '$o=[];foreach(["cb_operator","administrator"] as $s){$r=get_role($s);$c=$r?$r->capabilities:[];ksort($c);$o[$s]=$c;}ksort($o);echo hash("sha256",wp_json_encode($o));')"
contains "$repair2" 'already canonical' 'Second repair was not a semantic no-op'; eq "$policy2" "$policy1" 'Second repair changed role semantics'
echo "[B1] Role Policy detect/repair/trust isolation/idempotence PASS"

wp_cli cb failsafe enable >/dev/null; wp_cli cb failsafe close-window >/dev/null
selftest="$(wp_cli cb failsafe test)"; contains "$selftest" 'Success: All failsafe checks passed.' 'Failsafe self-test failed'
wp_cli cb failsafe disable --reason='C2-B1 terminal conformance' >/dev/null
eq "$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_OPT,"");')" emergency 'Terminal disable failed'
wp_cli cb failsafe enable >/dev/null; eq "$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_OPT,"");')" '' 'Terminal enable failed'
rotate="$(wp_cli cb failsafe rotate-token)"; token="$(sed -nE 's/.*cb_core_bypass=([0-9a-f]{64}).*/\1/p' <<<"$rotate" | head -n1)"
[[ "$token" =~ ^[0-9a-f]{64}$ ]] || fail 'Terminal rotate-token did not issue 64-hex secret'
hash0="$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_TOK,"");')"; [[ "$hash0" != "$token" && -n "$hash0" ]] || fail 'Secret stored durably in plaintext'
eq "$(wp_cli_eval_args '$t=$args[0];echo wp_check_password($t,get_option(CB_CORE_BYPASS_TOK,""))?"yes":"no";' "$token")" yes 'Stored secret hash does not verify issued token'
echo "[B1] Terminal Failsafe + hash-at-rest PASS"

browser_operator_id="$(wp_cli user create cb-b1-browser-operator cb-b1-browser-operator@example.test --role=subscriber --user_pass=cb-b1-browser-operator-pass --porcelain)"
browser_add="$(wp_cli cb operator add "$browser_operator_id")"
contains "$browser_add" 'promoted to CB Operator' 'Fresh browser Console operator recovery failed'
failsafe_admin_id="$(wp_cli user create cb-b1-failsafe-admin cb-b1-failsafe-admin@example.test --role=administrator --user_pass=cb-b1-failsafe-admin-pass --porcelain)"
failsafe_admin_add="$(wp_cli cb operator add "$failsafe_admin_id")"
contains "$failsafe_admin_add" 'promoted to CB Operator' 'Fresh browser Failsafe Administrator approval failed'
browser_user_id="$(wp_cli user create cb-b1-browser-user cb-b1-browser-user@example.test --role=subscriber --user_pass=cb-b1-browser-user-pass --porcelain)"

port="${CB_B1_HTTP_PORT:-8099}"; site="http://127.0.0.1:$port"
wp_cli option update home "$site" >/dev/null; wp_cli option update siteurl "$site" >/dev/null
log="${RUNNER_TEMP:-/tmp}/cb-b1-http-$WP_VERSION.log"

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
	echo "[B1] Local WordPress HTTP server restarted after transient runner failure" >&2
}

B1_HTTP_RESULT=""
browser_request(){
	local attempt=1
	local max_attempts="${CB_B1_HTTP_MAX_ATTEMPTS:-4}"
	local exit_code=0
	local result=""

	B1_HTTP_RESULT=""
	while true; do
		if [[ -z "$pid" ]] || ! kill -0 "$pid" >/dev/null 2>&1; then
			restart_http_server
		fi

		if result="$(command curl "$@")"; then
			B1_HTTP_RESULT="$result"
			return 0
		else
			exit_code=$?
		fi

		if (( exit_code != 7 && exit_code != 52 )); then
			return "$exit_code"
		fi

		if (( attempt >= max_attempts )); then
			cat "$log" >&2
			echo "[B1] Transient localhost transport failure persisted after ${attempt} attempts (exit ${exit_code})" >&2
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

mapfile -t a < <(auth "$admin_id"); [[ ${#a[@]} -ge 4 ]] || fail 'Admin browser auth fixture failed'
admin_cookie="${a[0]}=${a[1]}"; admin_rest="${a[2]}"; admin_ajax="${a[3]}"
mapfile -t o < <(auth "$browser_operator_id"); operator_cookie="${o[0]}=${o[1]}"; operator_rest="${o[2]}"
mapfile -t f < <(auth "$failsafe_admin_id"); failsafe_cookie="${f[0]}=${f[1]}"; failsafe_ajax="${f[3]}"
mapfile -t u < <(auth "$browser_user_id"); user_cookie="${u[0]}=${u[1]}"; user_ajax="${u[3]}"

browser_request -sS -o /tmp/b1-admin-console.json -w '%{http_code}' -H "Cookie: $admin_cookie" -H "X-WP-Nonce: $admin_rest" "$site/?rest_route=/core-blueprint/v1/console/commands&WP_CLI=1"
code="$B1_HTTP_RESULT"; eq "$code" 403 'Browser Administrator fabricated trusted shell/Console access'; contains "$(cat /tmp/b1-admin-console.json)" cb_console_forbidden 'Console denial used wrong boundary'
browser_request -sS -o /tmp/b1-operator-console.json -w '%{http_code}' -H "Cookie: $operator_cookie" -H "X-WP-Nonce: $operator_rest" "$site/?rest_route=/core-blueprint/v1/console/commands"
code="$B1_HTTP_RESULT"; eq "$code" 200 'Approved operator cannot reach browser Console'; catalog="$(cat /tmp/b1-operator-console.json)"; contains "$catalog" cb-permissions-repair-role-policy 'Console omitted repair command'; contains "$catalog" cb_manage_roles 'Console omitted command-specific gate'
wp_cli cb failsafe enable >/dev/null
browser_request -sS -o /tmp/b1-no-confirm.json -w '%{http_code}' -X POST -H "Cookie: $operator_cookie" -H "X-WP-Nonce: $operator_rest" -H 'Content-Type: application/json' --data '{"id":"cb-failsafe-disable","args":{}}' "$site/?rest_route=/core-blueprint/v1/console/run"
code="$B1_HTTP_RESULT"; eq "$code" 400 'Destructive Console command ran without confirm token'; contains "$(cat /tmp/b1-no-confirm.json)" cb_console_confirm_required 'Console confirm denial used wrong contract'; eq "$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_OPT,"");')" '' 'Rejected Console request mutated state'
echo "[B1] Terminal vs browser Console trust/confirm boundary PASS"

success0="$(audit_count failsafe_emergency_activated)"
browser_request -sS -o /tmp/b1-no-nonce.json -w '%{http_code}' -X POST -H "Cookie: $admin_cookie" --data 'action=cb_core_panic_activate' --data 'password=cb-cli-password-only-for-ci' "$site/wp-admin/admin-ajax.php"
code="$B1_HTTP_RESULT"; eq "$code" 403 'Panic accepted missing nonce'
browser_request -sS -o /tmp/b1-non-admin.json -w '%{http_code}' -X POST -H "Cookie: $user_cookie" --data 'action=cb_core_panic_activate' --data-urlencode "nonce=$user_ajax" --data 'password=cb-b1-browser-user-pass' "$site/wp-admin/admin-ajax.php"
code="$B1_HTTP_RESULT"; eq "$code" 403 'Panic accepted missing manage_options'
browser_request -sS -o /tmp/b1-wrong-pass.json -w '%{http_code}' -X POST -H "Cookie: $failsafe_cookie" --data 'action=cb_core_panic_activate' --data-urlencode "nonce=$failsafe_ajax" --data 'password=wrong' "$site/wp-admin/admin-ajax.php"
code="$B1_HTTP_RESULT"; eq "$code" 401 'Panic accepted wrong password'
eq "$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_OPT,"");')" '' 'Rejected browser recovery mutated Layer 2'; eq "$(audit_count failsafe_emergency_activated)" "$success0" 'Rejected browser recovery emitted success audit'
browser_request -sS -o /tmp/b1-panic-ok.json -w '%{http_code}' -X POST -H "Cookie: $failsafe_cookie" --data 'action=cb_core_panic_activate' --data-urlencode "nonce=$failsafe_ajax" --data 'password=cb-b1-failsafe-admin-pass' --data 'reason=C2-B1' "$site/wp-admin/admin-ajax.php"
code="$B1_HTTP_RESULT"; eq "$code" 200 'Valid browser panic failed'; eq "$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_OPT,"");')" emergency 'Valid browser panic did not mutate'; eq "$(audit_count failsafe_emergency_activated)" "$((success0+1))" 'Valid browser panic audit mismatch'
browser_request -sS -o /tmp/b1-panic-off.json -w '%{http_code}' -X POST -H "Cookie: $failsafe_cookie" --data 'action=cb_core_panic_deactivate' --data-urlencode "nonce=$failsafe_ajax" "$site/wp-admin/admin-ajax.php"
code="$B1_HTTP_RESULT"; eq "$code" 200 'Browser panic deactivate failed'; eq "$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_OPT,"");')" '' 'Browser panic deactivate did not clear state'
echo "[B1] Browser nonce/manage_options/password/no-success-audit matrix PASS"

invalid="$(printf '0%.0s' {1..64})"; [[ "$invalid" != "$token" ]] || invalid="$(printf '1%.0s' {1..64})"
reject0="$(audit_count failsafe_bypass_url_rejected)"; used0="$(audit_count failsafe_bypass_url_used)"
browser_request -sS -o /tmp/b1-invalid.html "$site/?cb_core_bypass=$invalid"
eq "$(wp_cli eval 'echo get_transient(\CoreBlueprint\Core\Security\Failsafe::BYPASS_TRANSIENT)?:"";')" '' 'Invalid token opened window'; eq "$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_TOK,"");')" "$hash0" 'Invalid token mutated hash'; eq "$(audit_count failsafe_bypass_url_rejected)" "$((reject0+1))" 'Invalid token rejection audit mismatch'; eq "$(audit_count failsafe_bypass_url_used)" "$used0" 'Invalid token emitted success audit'
browser_request -sS -o /tmp/b1-valid.html "$site/?cb_core_bypass=$token"
contains "$(cat /tmp/b1-valid.html)" 'Emergency Bypass Active' 'Valid token missed confirmation surface'; eq "$(wp_cli eval 'echo get_transient(\CoreBlueprint\Core\Security\Failsafe::BYPASS_TRANSIENT)?:"";')" active 'Valid token did not open window'; eq "$(audit_count failsafe_bypass_url_used)" "$((used0+1))" 'Valid token success audit mismatch'
hash1="$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_TOK,"");')"; [[ "$hash1" != "$hash0" ]] || fail 'Valid token did not rotate hash'; eq "$(wp_cli_eval_args '$t=$args[0];echo wp_check_password($t,get_option(CB_CORE_BYPASS_TOK,""))?"yes":"no";' "$token")" no 'Used token still validates'
wp_cli cb failsafe close-window >/dev/null; hash2="$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_TOK,"");')"
browser_request -sS -o /tmp/b1-reuse.html "$site/?cb_core_bypass=$token"
eq "$(wp_cli eval 'echo get_transient(\CoreBlueprint\Core\Security\Failsafe::BYPASS_TRANSIENT)?:"";')" '' 'Reused token reopened window'; eq "$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_TOK,"");')" "$hash2" 'Reused token mutated hash'; eq "$(audit_count failsafe_bypass_url_rejected)" "$((reject0+2))" 'Reused token rejection audit mismatch'
echo "[B1] Secret token invalid/valid/rotate/reuse/audit lifecycle PASS"

before="$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_TOK,"");')"
browser_request -sS -o /tmp/b1-rotate-wrong.json -w '%{http_code}' -X POST -H "Cookie: $failsafe_cookie" --data 'action=cb_core_rotate_token' --data-urlencode "nonce=$failsafe_ajax" --data 'password=wrong' "$site/wp-admin/admin-ajax.php"
code="$B1_HTTP_RESULT"; eq "$code" 401 'Browser rotate accepted wrong password'; eq "$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_TOK,"");')" "$before" 'Rejected browser rotate mutated hash'
browser_request -sS -o /tmp/b1-rotate-ok.json -w '%{http_code}' -X POST -H "Cookie: $failsafe_cookie" --data 'action=cb_core_rotate_token' --data-urlencode "nonce=$failsafe_ajax" --data 'password=cb-b1-failsafe-admin-pass' "$site/wp-admin/admin-ajax.php"
code="$B1_HTTP_RESULT"; eq "$code" 200 'Valid browser rotate failed'; after="$(wp_cli eval 'echo get_option(CB_CORE_BYPASS_TOK,"");')"; [[ "$after" != "$before" ]] || fail 'Valid browser rotate did not change hash'
rotated_url="$(php -r '$j=json_decode(file_get_contents("/tmp/b1-rotate-ok.json"),true);echo (is_array($j)&&!empty($j["success"])&&is_array($j["data"]??null))?(string)($j["data"]["bypass_url"]??""):"";')"
rotated_token="$(sed -nE 's/.*cb_core_bypass=([0-9a-f]{64}).*/\1/p' <<<"$rotated_url" | head -n1)"
[[ "$rotated_token" =~ ^[0-9a-f]{64}$ ]] || fail 'Browser rotate response omitted one-time 64-hex bypass URL'
eq "$(wp_cli_eval_args '$t=$args[0];echo wp_check_password($t,get_option(CB_CORE_BYPASS_TOK,""))?"yes":"no";' "$rotated_token")" yes 'Browser rotate response token does not match stored hash'
eq "$(wp_cli_eval_args 'echo get_transient("cb_core_new_token_".(int)$args[0])?"yes":"no";' "$failsafe_admin_id")" no 'Browser rotate persisted legacy one-time display transient'
echo "[B1] Browser token rotation response-only lifecycle PASS"

failsafe_admin_remove="$(wp_cli cb operator remove "$failsafe_admin_id" --force)"; contains "$failsafe_admin_remove" 'demoted from CB Operator' 'Browser Failsafe Administrator operator remove failed'
browser_remove="$(wp_cli cb operator remove "$browser_operator_id" --force)"; contains "$browser_remove" 'demoted from CB Operator' 'Browser Console operator remove failed'
remove="$(wp_cli cb operator remove "$candidate_id" --force)"; contains "$remove" 'demoted from CB Operator' 'Operator remove failed'; contains "$(wp_cli cb permissions status)" 'Operator count:           0' 'Operator remove did not restore zero operators'; eq "$(wp_cli_eval_args '$u=get_userdata((int)$args[0]);echo (get_user_meta($u->ID,"_cb_core_privileged_approval",true)||get_user_meta($u->ID,"_cb_core_privileged_review",true))?"present":"clear";' "$candidate_id")" clear 'Operator remove left trust metadata'
eq "$(role_hash cb_b1_unrelated)" "$unrelated_role" 'B1 changed unrelated role'; eq "$(user_hash "$unrelated_id")" "$unrelated_user" 'B1 changed unrelated user'
echo "[B1] Canonical operator remove + unrelated isolation PASS"

echo "[B1] P2-2/P2-3/P2-4 are inventory-only here; no production repair and no intentional red assertion."
echo "[B1] C2-B1 real WP-CLI / Role Policy / Failsafe conformance PASS"
