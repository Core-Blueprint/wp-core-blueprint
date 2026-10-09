#!/usr/bin/env bash
set -euo pipefail

WP_VERSION="${1:-}"
[[ "$WP_VERSION" =~ ^7\.(0|1)$ ]] || { echo "Usage: $0 <7.0|7.1>" >&2; exit 64; }
: "${WP_CORE_DIR:?WP_CORE_DIR is required}"
: "${CB_WP_CLI_PHAR:?CB_WP_CLI_PHAR is required}"

wp_cli(){ php "$CB_WP_CLI_PHAR" --path="$WP_CORE_DIR" --no-color "$@"; }
wp_cli_eval_args(){ local php_code="$1"; shift; printf '<?php\n%s\n' "$php_code" | wp_cli eval-file - "$@"; }
fail(){ echo "[C2-C] FAIL: $*" >&2; exit 1; }
eq(){ [[ "$1" == "$2" ]] || fail "$3 (expected '$2', got '$1')"; }
neq(){ [[ "$1" != "$2" ]] || fail "$3 (both were '$1')"; }
contains(){ grep -Fq "$2" <<<"$1" || { printf '%s\n' "$1" >&2; fail "$3 (missing: $2)"; }; }
audit_count(){ wp_cli_eval_args '$q=\CoreBlueprint\Core\Log\AuditLog::query(["event_type"=>$args[0],"per_page"=>1]);echo (int)$q["total"];' "$1"; }
audit_context(){ wp_cli_eval_args '$q=\CoreBlueprint\Core\Log\AuditLog::query(["event_type"=>$args[0],"per_page"=>1]);$r=$q["rows"][0]??null;echo $r?wp_json_encode($r->context_decoded??[]):"";' "$1"; }
state_hash(){ wp_cli_eval_args '$id=(int)$args[0];$keys=["_cb_media_replaced_at","_cb_media_replaced_by","_cb_media_replace_revision"];$m=[];foreach($keys as $k){$x=metadata_exists("post",$id,$k);$m[$k]=["exists"=>$x,"value"=>$x?get_post_meta($id,$k,true):null];}$x=metadata_exists("post",$id,"_wp_attachment_metadata");$s=["attached"=>wp_normalize_path((string)get_attached_file($id,true)),"metadata_exists"=>$x,"metadata"=>$x?get_post_meta($id,"_wp_attachment_metadata",true):null,"replacement_meta"=>$m];echo hash("sha256",serialize($s));' "$1"; }
metadata_hash(){ wp_cli_eval_args '$id=(int)$args[0];$x=metadata_exists("post",$id,"_wp_attachment_metadata");echo $x?hash("sha256",serialize(get_post_meta($id,"_wp_attachment_metadata",true))):"none";' "$1"; }
file_hash(){ wp_cli_eval_args '$f=get_attached_file((int)$args[0],true);echo is_string($f)&&is_file($f)?hash_file("sha256",$f):"missing";' "$1"; }
meta_value(){ wp_cli_eval_args 'echo (string)get_post_meta((int)$args[0],$args[1],true);' "$1" "$2"; }
meta_exists(){ wp_cli_eval_args 'echo metadata_exists("post",(int)$args[0],$args[1])?"yes":"no";' "$1" "$2"; }
auth(){ wp_cli_eval_args '$u=get_userdata((int)$args[0]);$e=time()+3600;$t=WP_Session_Tokens::get_instance($u->ID)->create($e);$c=wp_generate_auth_cookie($u->ID,$e,"logged_in",$t);$_COOKIE[LOGGED_IN_COOKIE]=$c;wp_set_current_user($u->ID);echo LOGGED_IN_COOKIE,"\n",$c,"\n",wp_create_nonce($args[1]),"\n";' "$1" "$2"; }

pid=""
operator_id=""
attachment_id=""
fixture_dir="${RUNNER_TEMP:-/tmp}/cb-c2c-fixtures-$WP_VERSION"
fault_plugin="$WP_CORE_DIR/wp-content/mu-plugins/cb-c2c-media-replace-fault.php"

cleanup(){
	if [[ -n "$pid" ]]; then kill "$pid" >/dev/null 2>&1 || true; fi
	rm -f "$fault_plugin" >/dev/null 2>&1 || true
	wp_cli option delete cb_c2c_fault_attachment >/dev/null 2>&1 || true
	wp_cli option delete cb_c2c_fault_key >/dev/null 2>&1 || true
	if [[ -n "$attachment_id" ]]; then wp_cli post delete "$attachment_id" --force >/dev/null 2>&1 || true; fi
	if [[ -n "$operator_id" ]]; then
		wp_cli cb operator remove "$operator_id" --force >/dev/null 2>&1 || true
		wp_cli user delete "$operator_id" --yes >/dev/null 2>&1 || true
	fi
	rm -rf "$fixture_dir" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "[C2-C] Starting Media Replace durable-state conformance on WordPress $WP_VERSION"
wp_cli plugin is-active core-blueprint >/dev/null 2>&1 || fail 'Core Blueprint is not active from the canonical CLI harness'
mkdir -p "$fixture_dir" "$WP_CORE_DIR/wp-content/mu-plugins"

python3 - "$fixture_dir" <<'PY'
import math
import os
import struct
import sys
import wave

root = sys.argv[1]
for name, frequency, seconds in [
    ("initial.wav", 220.0, 0.25),
    ("replacement.wav", 440.0, 0.50),
    ("failure.wav", 660.0, 0.75),
]:
    rate = 8000
    frames = int(rate * seconds)
    path = os.path.join(root, name)
    with wave.open(path, "wb") as out:
        out.setnchannels(1)
        out.setsampwidth(2)
        out.setframerate(rate)
        samples = bytearray()
        for n in range(frames):
            value = int(12000 * math.sin(2.0 * math.pi * frequency * n / rate))
            samples.extend(struct.pack("<h", value))
        out.writeframes(bytes(samples))
PY

operator_id="$(wp_cli user create cb-c2c-operator cb-c2c-operator@example.test --role=administrator --user_pass=cb-c2c-operator-pass --porcelain)"
add="$(wp_cli cb operator add "$operator_id")"
contains "$add" 'promoted to CB Operator' 'Could not create approved Media Replace operator'
wp_cli eval '\CoreBlueprint\Core\MediaReplace\State::set_enabled(true,"c2c-conformance");' >/dev/null

attachment_id="$(wp_cli_eval_args '$src=$args[0];$uid=(int)$args[1];$up=wp_upload_dir();if(!empty($up["error"]))throw new RuntimeException((string)$up["error"]);wp_mkdir_p($up["path"]);$dest=wp_normalize_path(trailingslashit($up["path"])."cb-c2c-media.wav");if(!copy($src,$dest))throw new RuntimeException("copy failed");$ft=wp_check_filetype(wp_basename($dest),null);$id=wp_insert_attachment(["post_mime_type"=>(string)($ft["type"]??"audio/wav"),"post_title"=>"C2-C Media Replace fixture","post_status"=>"inherit","post_author"=>$uid],$dest);if(is_wp_error($id)||!$id)throw new RuntimeException("attachment insert failed");update_attached_file((int)$id,$dest);require_once ABSPATH."wp-admin/includes/image.php";$meta=wp_generate_attachment_metadata((int)$id,$dest);if(!is_array($meta)||[]===$meta)throw new RuntimeException("initial metadata generation failed");wp_update_attachment_metadata((int)$id,$meta);echo (int)$id;' "$fixture_dir/initial.wav" "$operator_id")"
[[ "$attachment_id" =~ ^[0-9]+$ ]] || fail 'Initial attachment fixture did not return an ID'

eq "$(wp_cli_eval_args 'wp_set_current_user((int)$args[0]);echo current_user_can(\CoreBlueprint\Core\MediaReplace\Capabilities::REPLACE_MEDIA,(int)$args[1])?"yes":"no";' "$operator_id" "$attachment_id")" yes 'Approved operator cannot replace its attachment'
initial_metadata_hash="$(metadata_hash "$attachment_id")"
[[ "$initial_metadata_hash" != "none" ]] || fail 'Initial attachment has no durable metadata'
initial_file_hash="$(file_hash "$attachment_id")"

port="${CB_C2C_HTTP_PORT:-8110}"
site="http://127.0.0.1:$port"
wp_cli option update home "$site" >/dev/null
wp_cli option update siteurl "$site" >/dev/null
log="${RUNNER_TEMP:-/tmp}/cb-c2c-http-$WP_VERSION.log"
php -S "127.0.0.1:$port" -t "$WP_CORE_DIR" >"$log" 2>&1 & pid=$!
for _ in {1..30}; do curl -sS -o /dev/null "$site/wp-login.php" 2>/dev/null && break; sleep .2; done
kill -0 "$pid" >/dev/null 2>&1 || { cat "$log" >&2; fail 'Local WordPress HTTP server failed'; }

perform_replace(){
	local fixture="$1"
	local body="$2"
	mapfile -t session < <(auth "$operator_id" "cb_core_replace_media_$attachment_id")
	[[ ${#session[@]} -ge 3 ]] || fail 'Browser Media Replace auth fixture failed'
	local cookie="${session[0]}=${session[1]}"
	local nonce="${session[2]}"
	curl -sS -o "$body" -w '%{http_code}' -X POST \
		-H "Cookie: $cookie" \
		-F 'action=cb_core_replace_media' \
		-F "attachment_id=$attachment_id" \
		-F "_wpnonce=$nonce" \
		-F 'media_replace_acknowledgement=1' \
		-F "return=$site/wp-admin/upload.php" \
		-F "replacement_file=@$fixture;type=audio/wav" \
		"$site/wp-admin/admin-post.php"
}

success0="$(audit_count media_file_replaced)"
failure0="$(audit_count media_replace_failed)"
code="$(perform_replace "$fixture_dir/replacement.wav" "$fixture_dir/success.html")"
eq "$code" 302 'Real browser Media Replace success request did not redirect'
eq "$(audit_count media_file_replaced)" "$((success0+1))" 'Successful replacement did not emit exactly one success audit'
eq "$(audit_count media_replace_failed)" "$failure0" 'Successful replacement emitted a failure audit'

success_file_hash="$(file_hash "$attachment_id")"
neq "$success_file_hash" "$initial_file_hash" 'Successful replacement did not commit new file bytes'
success_metadata_hash="$(metadata_hash "$attachment_id")"
[[ "$success_metadata_hash" != "none" ]] || fail 'Successful replacement removed attachment metadata'
neq "$success_metadata_hash" "$initial_metadata_hash" 'Successful replacement metadata did not change with the new WAV duration'
eq "$(meta_exists "$attachment_id" _cb_media_replaced_at)" yes 'Successful replacement did not persist replacement time'
eq "$(meta_exists "$attachment_id" _cb_media_replaced_by)" yes 'Successful replacement did not persist replacement actor'
eq "$(meta_exists "$attachment_id" _cb_media_replace_revision)" yes 'Successful replacement did not persist cache revision'
eq "$(meta_value "$attachment_id" _cb_media_replaced_by)" "$operator_id" 'Successful replacement persisted the wrong replacement actor'
[[ -n "$(meta_value "$attachment_id" _cb_media_replaced_at)" ]] || fail 'Successful replacement persisted an empty replacement time'
[[ -n "$(meta_value "$attachment_id" _cb_media_replace_revision)" ]] || fail 'Successful replacement persisted an empty cache revision'
committed_state="$(state_hash "$attachment_id")"
echo "[C2-C] Durable success readback PASS"

cat > "$fault_plugin" <<'PHP'
<?php
add_filter(
	'update_post_metadata',
	static function ( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		unset( $meta_value, $prev_value );
		$target = (int) get_option( 'cb_c2c_fault_attachment', 0 );
		$key    = (string) get_option( 'cb_c2c_fault_key', '' );
		if ( null === $check && $target > 0 && (int) $object_id === $target && '' !== $key && $meta_key === $key ) {
			delete_option( 'cb_c2c_fault_key' );
			return false;
		}
		return $check;
	},
	PHP_INT_MAX,
	5
);
PHP
wp_cli option update cb_c2c_fault_attachment "$attachment_id" >/dev/null

# First force the attachment-metadata write itself to be rejected. Because the
# failure WAV has a different duration/filesize, old and expected metadata are
# observably different and the service must detect the stale durable value.
wp_cli option update cb_c2c_fault_key _wp_attachment_metadata >/dev/null
success_before="$(audit_count media_file_replaced)"
failure_before="$(audit_count media_replace_failed)"
code="$(perform_replace "$fixture_dir/failure.wav" "$fixture_dir/metadata-failure.html")"
eq "$code" 302 'Metadata persistence failure request did not return through the handler'
eq "$(audit_count media_file_replaced)" "$success_before" 'Rejected metadata persistence emitted a false success audit'
eq "$(audit_count media_replace_failed)" "$((failure_before+1))" 'Rejected metadata persistence did not emit exactly one failure audit'
contains "$(audit_context media_replace_failed)" '"reason":"persistence_verify_failed"' 'Metadata persistence mismatch was not classified as a durable-state failure'
eq "$(state_hash "$attachment_id")" "$committed_state" 'Metadata persistence failure did not restore exact prior durable state'
eq "$(file_hash "$attachment_id")" "$success_file_hash" 'Metadata persistence failure did not restore prior file bytes'
echo "[C2-C] Metadata-write mismatch rollback PASS"

# Then reject only the final replacement revision. This happens after the
# attached-file, metadata, replaced-at and replaced-by writes, so exact rollback
# must restore every durable component rather than merely the physical file.
wp_cli option update cb_c2c_fault_key _cb_media_replace_revision >/dev/null
success_before="$(audit_count media_file_replaced)"
failure_before="$(audit_count media_replace_failed)"
code="$(perform_replace "$fixture_dir/failure.wav" "$fixture_dir/revision-failure.html")"
eq "$code" 302 'Replacement-meta persistence failure request did not return through the handler'
eq "$(audit_count media_file_replaced)" "$success_before" 'Rejected replacement-meta persistence emitted a false success audit'
eq "$(audit_count media_replace_failed)" "$((failure_before+1))" 'Rejected replacement-meta persistence did not emit exactly one failure audit'
contains "$(audit_context media_replace_failed)" '"reason":"persistence_verify_failed"' 'Replacement-meta mismatch was not classified as a durable-state failure'
eq "$(state_hash "$attachment_id")" "$committed_state" 'Replacement-meta failure did not restore exact prior durable state'
eq "$(file_hash "$attachment_id")" "$success_file_hash" 'Replacement-meta failure did not restore prior file bytes'

eq "$(wp_cli_eval_args 'echo false===get_option("cb_c2c_fault_key",false)?"yes":"no";')" yes 'One-shot persistence fault remained armed after rollback'
echo "[C2-C] Replacement-meta mismatch rollback PASS"

echo "[C2-C] Media Replace durable persistence / rollback conformance PASS"
