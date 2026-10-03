<?php
declare(strict_types=1);
/**
 * Captures WordPress mail success/failure events into the dedicated mail log.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail\Log;

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Mail\Message;
use CoreBlueprint\Core\Mail\Settings;
use CoreBlueprint\Core\Mail\TestContext;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class DeliveryLogger {

	private static float $started = 0.0;

	public static function boot(): void {
		add_filter( 'wp_mail', [ __CLASS__, 'capture_start' ], 1 );
		add_action( 'wp_mail_succeeded', [ __CLASS__, 'success' ], 10, 1 );
		add_action( 'wp_mail_failed', [ __CLASS__, 'failure' ], 10, 1 );
	}

	public static function capture_start( array $atts ): array {
		self::$started = microtime( true );
		return $atts;
	}

	public static function success( array $mail_data ): void {
		$message  = Message::from_atts( $mail_data );
		$provider = isset( $mail_data['cb_provider'] ) ? sanitize_key( (string) $mail_data['cb_provider'] ) : Settings::provider();
		$duration = isset( $mail_data['cb_duration_ms'] )
			? max( 0, (int) $mail_data['cb_duration_ms'] )
			: self::duration();

		Repository::insert( [
			'status'              => 'sent',
			'provider'            => $provider,
			'transport'           => 'brevo' === $provider ? 'api' : 'smtp',
			'from_email'          => $message['from_email'],
			'from_name'           => $message['from_name'],
			'recipients'          => $message['to'],
			'cc'                  => $message['cc'],
			'bcc'                 => $message['bcc'],
			'reply_to'            => $message['reply_to'],
			'subject'             => $message['subject'],
			'provider_message_id' => (string) ( $mail_data['cb_message_id'] ?? '' ),
			'attachment_count'    => count( $message['attachments'] ),
			'embed_count'         => count( $message['embeds'] ),
			'duration_ms'         => $duration,
			'is_test'             => isset( $mail_data['cb_is_test'] ) ? (bool) $mail_data['cb_is_test'] : TestContext::is_active(),
		] );

		self::$started = 0.0;
	}

	public static function failure( WP_Error $error ): void {
		$data     = $error->get_error_data();
		$data     = is_array( $data ) ? $data : [];
		$message  = Message::from_atts( $data );
		$provider = isset( $data['cb_provider'] ) ? sanitize_key( (string) $data['cb_provider'] ) : Settings::provider();
		$duration = isset( $data['cb_duration_ms'] )
			? max( 0, (int) $data['cb_duration_ms'] )
			: self::duration();

		$code = sanitize_key( (string) $error->get_error_code() );
		$text = sanitize_text_field( $error->get_error_message() );

		Repository::insert( [
			'status'           => 'failed',
			'provider'         => $provider,
			'transport'        => 'brevo' === $provider ? 'api' : 'smtp',
			'from_email'       => $message['from_email'],
			'from_name'        => $message['from_name'],
			'recipients'       => $message['to'],
			'cc'               => $message['cc'],
			'bcc'              => $message['bcc'],
			'reply_to'         => $message['reply_to'],
			'subject'          => $message['subject'],
			'error_code'       => $code,
			'error_message'    => $text,
			'attachment_count' => count( $message['attachments'] ),
			'embed_count'      => count( $message['embeds'] ),
			'duration_ms'      => $duration,
			'is_test'          => isset( $data['cb_is_test'] ) ? (bool) $data['cb_is_test'] : TestContext::is_active(),
		] );

		// System Log only receives the technical failure signal. Recipient,
		// recipient and subject remain in the purpose-built Mail Log only; the body is never persisted.
		AuditLog::log( 'system.mail_failed', 'warning', [
			'provider'   => $provider,
			'error_code' => $code,
			'message'    => $text,
		] );

		self::$started = 0.0;
	}

	private static function duration(): int {
		return self::$started > 0
			? max( 0, (int) round( ( microtime( true ) - self::$started ) * 1000 ) )
			: 0;
	}
}
