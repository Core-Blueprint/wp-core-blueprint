<?php
declare(strict_types=1);
/**
 * Brevo HTTP API transport.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Mail\Transport;

use CB\Core\Mail\Message;
use CB\Core\Mail\Settings;
use CB\Core\Mail\TestContext;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class BrevoTransport implements TransportInterface {

	private const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

	public static function slug(): string {
		return 'brevo';
	}

	public static function boot(): void {
		add_filter( 'pre_wp_mail', [ __CLASS__, 'send' ], PHP_INT_MAX, 2 );
	}

	/**
	 * Short-circuit wp_mail and deliver through Brevo.
	 *
	 * @param null|bool $return Short-circuit value from earlier filters.
	 * @param array     $atts   wp_mail() arguments.
	 * @return null|bool
	 */
	public static function send( $return, array $atts ) {
		if ( null !== $return ) {
			return $return;
		}

		$started = microtime( true );
		$message = Message::from_atts( $atts );
		$api_key = Settings::brevo_api_key();

		if ( '' === $api_key || ! is_email( $message['from_email'] ) || empty( $message['to'] ) ) {
			return self::fail(
				'mail_configuration_invalid',
				__( 'Brevo mail settings are incomplete or invalid.', 'core-blueprint' ),
				$atts,
				$started
			);
		}

		// WordPress 6.9+ supports CID embeds as a first-class wp_mail()
		// argument. Brevo's transactional send endpoint currently documents
		// regular attachments but no CID/content-ID mapping. Fail explicitly
		// rather than reporting success with broken inline content.
		if ( ! empty( $message['embeds'] ) ) {
			return self::fail(
				'brevo_embeds_unsupported',
				__( 'This message contains inline embedded files that the Brevo API transport cannot preserve. Use Generic SMTP for this message type.', 'core-blueprint' ),
				$atts,
				$started
			);
		}

		$payload = [
			'sender'  => self::recipient( $message['from_email'], $message['from_name'] ),
			'to'      => array_map( [ __CLASS__, 'recipient_from_array' ], $message['to'] ),
			'subject' => $message['subject'],
		];

		if ( str_contains( $message['content_type'], 'html' ) || 'multipart/alternative' === $message['content_type'] ) {
			$payload['htmlContent'] = $message['message'];
		} else {
			$payload['textContent'] = wp_strip_all_tags( $message['message'] );
		}

		if ( ! empty( $message['cc'] ) ) {
			$payload['cc'] = array_map( [ __CLASS__, 'recipient_from_array' ], $message['cc'] );
		}
		if ( ! empty( $message['bcc'] ) ) {
			$payload['bcc'] = array_map( [ __CLASS__, 'recipient_from_array' ], $message['bcc'] );
		}
		if ( ! empty( $message['reply_to'] ) ) {
			$payload['replyTo'] = self::recipient_from_array( $message['reply_to'][0] );
		}

		$attachments = self::attachments( $message['attachments'] );
		if ( is_wp_error( $attachments ) ) {
			return self::fail(
				$attachments->get_error_code(),
				$attachments->get_error_message(),
				$atts,
				$started
			);
		}
		if ( ! empty( $attachments ) ) {
			$payload['attachment'] = $attachments;
		}

		$response = wp_safe_remote_post( self::ENDPOINT, [
			'timeout' => 15,
			'headers' => [
				'api-key'      => $api_key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			],
			'body' => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			return self::fail(
				(string) $response->get_error_code(),
				$response->get_error_message(),
				$atts,
				$started
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$body   = is_array( $body ) ? $body : [];

		if ( 201 !== $status ) {
			/* translators: %d: HTTP status code returned by Brevo. */
			$default_message = sprintf( __( 'Brevo returned HTTP %d.', 'core-blueprint' ), $status );
			$message_text = isset( $body['message'] ) && is_string( $body['message'] )
				? sanitize_text_field( $body['message'] )
				: $default_message;
			return self::fail( 'brevo_http_' . $status, $message_text, $atts, $started );
		}

		$mail_data = $atts;
		$mail_data['cb_provider']   = 'brevo';
		$mail_data['cb_message_id'] = isset( $body['messageId'] ) && is_string( $body['messageId'] )
			? trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $body['messageId'] ) ?? '' )
			: '';
		$mail_data['cb_duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		$mail_data['cb_is_test']     = TestContext::is_active();

		do_action( 'wp_mail_succeeded', $mail_data );
		return true;
	}

	private static function fail( string $code, string $message, array $atts, float $started ): bool {
		$data = $atts;
		$data['cb_provider']    = 'brevo';
		$data['cb_duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		$data['cb_is_test']     = TestContext::is_active();

		do_action( 'wp_mail_failed', new WP_Error( $code, $message, $data ) );
		return false;
	}

	private static function recipient( string $email, string $name = '' ): array {
		$out = [ 'email' => $email ];
		if ( '' !== $name ) {
			$out['name'] = $name;
		}
		return $out;
	}

	private static function recipient_from_array( array $recipient ): array {
		return self::recipient( (string) $recipient['email'], (string) ( $recipient['name'] ?? '' ) );
	}

	/** @return array<int,array{name:string,content:string}>|WP_Error */
	private static function attachments( array $paths ): array|WP_Error {
		$out = [];
		foreach ( $paths as $path ) {
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				return new WP_Error(
					'mail_attachment_unreadable',
					/* translators: %s: attachment file name. */
					sprintf( __( 'Mail attachment is not readable: %s', 'core-blueprint' ), basename( $path ) )
				);
			}
			$content = file_get_contents( $path );
			if ( false === $content ) {
				return new WP_Error(
					'mail_attachment_read_failed',
					/* translators: %s: attachment file name. */
					sprintf( __( 'Mail attachment could not be read: %s', 'core-blueprint' ), basename( $path ) )
				);
			}
			$out[] = [
				'name'    => basename( $path ),
				'content' => base64_encode( $content ),
			];
		}
		return $out;
	}
}
