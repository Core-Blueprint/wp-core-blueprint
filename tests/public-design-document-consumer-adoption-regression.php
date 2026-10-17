<?php
declare(strict_types=1);

/**
 * Source-only native WordPress Mail safety regression. No WordPress bootstrap,
 * account operations, wp_mail(), network, options database, Docker or SQL.
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	class WP_User {
		public string $user_login = 'alice';
		public string $display_name = 'Alice';
		public string $user_email = 'alice@example.test';
	}
	$GLOBALS['cb_native_can_manage'] = false;
	$GLOBALS['cb_native_transients'] = [];
	function __( string $message, string $domain = '' ): string { return $message; }
	function esc_html__( string $message, string $domain = '' ): string { return $message; }
	function esc_url_raw( string $url, array $protocols = [] ): string { return $url; }
	function get_bloginfo( string $name ): string { return 'Sandbox'; }
	function get_option( string $name, mixed $default = null ): mixed { return $default; }
	function home_url( string $path ): string { return 'https://sandbox.invalid' . $path; }
	function network_site_url( string $path, string $scheme = '' ): string { return 'https://sandbox.invalid/' . $path; }
	function sanitize_email( string $email ): string { return $email; }
	function sanitize_text_field( string $text ): string { return $text; }
	function sanitize_user( string $value, bool $strict = false ): string { return $value; }
	function current_user_can( string $cap ): bool { return $GLOBALS['cb_native_can_manage']; }
	function get_transient( string $key ): mixed { return $GLOBALS['cb_native_transients'][$key] ?? false; }
	function set_transient( string $key, mixed $value, int $expiration ): bool {
		$GLOBALS['cb_native_transients'][$key] = $value;
		return true;
	}
	function cb_assert( bool $condition, string $label ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( 'Native Mail source regression failed: ' . $label );
		}
	}
}

namespace CoreBlueprint\Core\Design\Document {
	final class Authority {
		public static function inspect(): array { return [ 'status' => 'active' ]; }
	}
}
namespace CoreBlueprint\Core\Log {
	final class AuditLog {
		public static array $records = [];
		public static function log( string $type, string $severity, array $context = [] ): void {
			self::$records[] = [ 'event' => $type, 'level' => $severity, 'context' => $context ];
		}
	}
}
namespace CoreBlueprint\Core\Mail {
	final class DesignerState {
		public static function is_enabled(): bool { return true; }
	}
}
namespace CoreBlueprint\Core\Mail\Designer {
	final class DocumentUnavailable extends \RuntimeException {
		public function __construct( public readonly string $reason ) {
			parent::__construct( 'safe test reason' );
		}
	}
	final class Renderer {
		public static string $mode = 'error';
		public static function render( string $template, array $context ): ?array {
			if ( 'error' === self::$mode ) {
				throw new DocumentUnavailable( 'corrupt' );
			}
			if ( 'null' === self::$mode ) {
				return null;
			}
			return [ 'subject' => 'Designed', 'html' => '<p>Designed</p>' ];
		}
		public static function html_headers( mixed $headers ): array {
			return [ 'Content-Type: text/html; charset=UTF-8' ];
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Mail/Designer/WordPressIntegration.php';

	$user = new WP_User();
	$incoming = [
		'to' => 'alice@example.test',
		'subject' => 'IMPORTANT ORIGINAL SUBJECT',
		'message' => 'Click https://sandbox.invalid/reset?key=PRIVATE-RESET-KEY',
		'headers' => [ 'X-Security' => 'retained' ],
		'attachments' => [ '/private/never-logged' ],
		'extra_unexpected' => [ 'must' => 'survive' ],
	];
	$hooks = [
		'password reset' => static fn () => \CoreBlueprint\Core\Mail\Designer\WordPressIntegration::password_reset(
			$incoming, 'PRIVATE-RESET-KEY', 'alice', $user
		),
		'new user' => static fn () => \CoreBlueprint\Core\Mail\Designer\WordPressIntegration::new_user(
			$incoming, $user, 'Sandbox'
		),
		'new user admin' => static fn () => \CoreBlueprint\Core\Mail\Designer\WordPressIntegration::new_user_admin(
			$incoming, $user, 'Sandbox'
		),
		'password changed' => static fn () => \CoreBlueprint\Core\Mail\Designer\WordPressIntegration::password_changed(
			$incoming, [ 'user_login' => 'alice' ], []
		),
		'email changed' => static fn () => \CoreBlueprint\Core\Mail\Designer\WordPressIntegration::email_changed(
			$incoming, [ 'user_login' => 'alice' ], [ 'user_email' => 'alice+new@example.test' ]
		),
		'password changed admin' => static fn () => \CoreBlueprint\Core\Mail\Designer\WordPressIntegration::password_changed_admin(
			$incoming, $user, 'Sandbox'
		),
	];

	foreach ( $hooks as $label => $invoke ) {
		$actual = $invoke();
		cb_assert( $actual === $incoming, $label . ' must preserve the exact incoming array' );
	}
	$records = \CoreBlueprint\Core\Log\AuditLog::$records;
	cb_assert( count( $records ) === 1, 'deduplicate six security hooks into one audit window' );
	$public = json_encode( $records, JSON_THROW_ON_ERROR );
	foreach ( [ 'PRIVATE-RESET-KEY', 'alice@example.test', 'ORIGINAL SUBJECT', '/private/', 'sandbox.invalid', 'password reset' ] as $secret ) {
		cb_assert( ! str_contains( $public, $secret ), 'no sensitive audit content' );
	}
	cb_assert( ( $records[0]['context']['reason_code'] ?? null ) === 'corrupt', 'safe machine reason only' );
	ob_start();
	\CoreBlueprint\Core\Mail\Designer\WordPressIntegration::integrity_notice();
	$unprivileged_notice = ob_get_clean();
	cb_assert( '' === $unprivileged_notice, 'no notice for unauthorized visitors' );
	$GLOBALS['cb_native_can_manage'] = true;
	ob_start();
	\CoreBlueprint\Core\Mail\Designer\WordPressIntegration::integrity_notice();
	$privileged_notice = ob_get_clean();
	cb_assert( str_contains( $privileged_notice, 'Mail Designer' ), 'operator-visible notice' );
	cb_assert( ! str_contains( $privileged_notice, 'PRIVATE-RESET-KEY' ), 'no token in notice' );

	\CoreBlueprint\Core\Mail\Designer\Renderer::$mode = 'success';
	$rendered = $hooks['password reset']();
	cb_assert( $rendered['to'] === $incoming['to'], 'successful customization retains recipient' );
	cb_assert( $rendered['subject'] === 'Designed', 'successful customization applied' );
	cb_assert( $rendered['message'] === '<p>Designed</p>', 'HTML applied' );
	cb_assert( $rendered['attachments'] === $incoming['attachments'], 'attachments unchanged' );
	cb_assert( count( \CoreBlueprint\Core\Log\AuditLog::$records ) === 1, 'no false integrity audit on successful render' );
	\CoreBlueprint\Core\Mail\Designer\Renderer::$mode = 'null';
	cb_assert( $hooks['password reset']() === $incoming, 'invalid active render preserves native security mail' );

	echo "Base A2.3a native WordPress security notification contract: PASS (six hooks, audit, notice, success)\n";
}
