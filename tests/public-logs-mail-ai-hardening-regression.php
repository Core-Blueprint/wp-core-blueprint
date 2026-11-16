<?php
declare(strict_types=1);

/** WordPress-free regression for Core Blueprint Mail and AI log safety. */
namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', '/tmp/' );

	class WP_Error {
		public function __construct( private string $code, private string $message, private array $data = [] ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}

	function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? ''; }
	function sanitize_text_field( string $value ): string { return strip_tags( $value ); }
	function sanitize_email( string $value ): string { return trim( $value ); }
	function is_email( string $value ): string|false { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
}
namespace CoreBlueprint\Core\Mail {
	final class Message {
		public static function from_atts( array $atts ): array {
			return [
				'from_email' => 'sender@example.test',
				'from_name' => 'Sender',
				'to' => [ [ 'email' => 'recipient@example.test', 'name' => 'Recipient' ] ],
				'cc' => [], 'bcc' => [], 'reply_to' => [],
				'subject' => 'Fixture mail subject',
				'attachments' => [], 'embeds' => [],
				'message' => $atts['message'] ?? '',
			];
		}
	}
	final class Settings { public static function provider(): string { return 'brevo'; } }
	final class TestContext { public static function is_active(): bool { return false; } }
}
namespace CoreBlueprint\Core\Log {
	final class AuditLog {
		public static array $events = [];
		public static function log( string $event, string $severity, array $context ): int {
			self::$events[] = compact( 'event', 'severity', 'context' );
			return count( self::$events );
		}
	}
}
namespace {
	use CoreBlueprint\Core\Mail\Log\Repository as MailRepository;
	use CoreBlueprint\Core\Mail\Log\DeliveryLogger;
	use CoreBlueprint\Core\Log\AuditLog;
	use CoreBlueprint\Core\AIGovernance\Privacy;

	$root = dirname( __DIR__ );
	require_once $root . '/src/Governance/ContextSanitizer.php';
	require_once $root . '/src/AIGovernance/Privacy.php';
	require_once $root . '/src/Mail/Log/Repository.php';
	require_once $root . '/src/Mail/Log/DeliveryLogger.php';

	$check = static function ( bool $condition, string $label ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "Logs Mail/AI hardening regression FAIL: {$label}\n" );
			exit( 1 );
		}
	};
	$fake_secret = 'CB_NEVER_STORE_RAW_VALUE';
	$safe = Privacy::sanitize_context( [
		'systemPrompt' => $fake_secret,
		'messages' => [ $fake_secret ],
		'responseBody' => $fake_secret,
		'nested' => [
			'toolOutput' => $fake_secret,
			'conversationHistory' => $fake_secret,
			'errorMessage' => $fake_secret,
		],
		'inputType' => 'object',
		'messageCount' => 2,
		'responseBodyHash' => 'hash-only',
		'approval_id' => 'approval-17',
	] );
	foreach ( [ 'systemPrompt', 'messages', 'responseBody' ] as $key ) {
		$check( '[redacted:metadata-only]' === $safe[ $key ], "redact {$key}" );
	}
	foreach ( [ 'toolOutput', 'conversationHistory', 'errorMessage' ] as $key ) {
		$check( '[redacted:metadata-only]' === $safe['nested'][ $key ], "redact nested {$key}" );
	}
	$check( 'object' === $safe['inputType'] && 2 === $safe['messageCount'], 'preserve safe count and type' );
	$check( 'hash-only' === $safe['responseBodyHash'] && 'approval-17' === $safe['approval_id'], 'preserve safe evidence' );
	$check( ! str_contains( (string) json_encode( $safe ), $fake_secret ), 'no raw AI content persisted' );

	$wpdb = new class {
		public string $prefix = 'wp_';
		public int $insert_id = 3;
		public array $inserts = [];
		public array $queries = [];
		public int|false $delete_result = 0;

		public function insert( string $table, array $row, array $formats ): int {
			$this->inserts[] = compact( 'table', 'row', 'formats' );
			return 1;
		}
		public function prepare( string $query, string $table ): string {
			if ( 1 !== substr_count( $query, '%i' ) ) {
				throw new \RuntimeException( 'Unexpected SQL prepare placeholders' );
			}
			return str_replace( '%i', $table, $query );
		}
		public function query( string $sql ): int|false {
			$this->queries[] = $sql;
			return $this->delete_result;
		}
		public function get_var( string $sql ): never {
			throw new \RuntimeException( 'Clear must not execute an unreliable pre-count' );
		}
	};

	DeliveryLogger::failure( new WP_Error( 'brevo_http_403', "Provider returned {$fake_secret}", [ 'message' => $fake_secret ] ) );
	$check( 1 === count( $wpdb->inserts ), 'Mail error row inserted' );
	$mail_row = $wpdb->inserts[0]['row'];
	$check( 'brevo_http_403' === $mail_row['error_code'], 'known machine error code retained' );
	$check( '' === $mail_row['error_message'], 'untrusted provider error text dropped' );
	$check( 1 === count( AuditLog::$events ), 'failure audit retained' );
	$check( 'system.mail_failed' === AuditLog::$events[0]['event'], 'failure audit event maintained' );
	$check( ! str_contains( (string) json_encode( [ $mail_row, AuditLog::$events ] ), $fake_secret ), 'Mail log and audit exclude raw provider message' );

	DeliveryLogger::failure( new WP_Error( 'api_key_' . $fake_secret, "Error {$fake_secret}", [] ) );
	$check( 'mail_delivery_failed' === $wpdb->inserts[1]['row']['error_code'], 'unknown raw error identifier replaced' );

	$wpdb->delete_result = false;
	$check( false === MailRepository::clear(), 'failed DELETE is not treated as success' );
	$wpdb->delete_result = 2;
	$check( 2 === MailRepository::clear(), 'confirmed deleted row count returned' );
	$wpdb->delete_result = 0;
	$check( 0 === MailRepository::clear(), 'empty DELETE returns zero' );
	$check( 3 === count( $wpdb->queries ) && 1 === count( array_unique( $wpdb->queries ) ), 'single prepared DELETE, no pre-count' );
	$check( 'DELETE FROM wp_cb_core_mail_log' === $wpdb->queries[0], 'bound Mail Log table' );

	$actions_source = file_get_contents( $root . '/src/Mail/Admin/Actions.php' );
	$check( is_string( $actions_source ) && str_contains( $actions_source, 'false === $deleted' ), 'clear action must guard storage failure' );

	echo "Logs Mail/AI privacy and clear-contract regressions: PASS\n";
}
