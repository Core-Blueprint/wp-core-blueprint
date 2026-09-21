<?php
declare(strict_types=1);
/**
 * Public scoped send API for registered Core Blueprint mail identities.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Mail;

defined( 'ABSPATH' ) || exit;

final class Sender {

	/**
	 * Send a WordPress email through a registered Core Blueprint sender identity.
	 *
	 * Unknown identities fail safely to the configured default sender.
	 *
	 * @param string|string[] $to
	 * @param string|string[] $headers
	 * @param string|string[] $attachments
	 */
	public static function send(
		string $identity_id,
		string|array $to,
		string $subject,
		string $message,
		string|array $headers = [],
		string|array $attachments = []
	): bool {
		$identity = SenderIdentityRegistry::get( $identity_id );
		$scoped_id = null !== $identity ? sanitize_key( $identity_id ) : '';
		$identity = $identity ?? SenderIdentityRegistry::default_identity();

		return self::send_with_identity(
			$scoped_id,
			$identity,
			$to,
			$subject,
			$message,
			$headers,
			$attachments,
			false
		);
	}

	/**
	 * Send only when the registered identity still matches a previously resolved snapshot.
	 *
	 * This is intended for durable workflows that commit sender values before
	 * asynchronous delivery. The caller cannot supply an arbitrary From address:
	 * the snapshot must still match the currently registered effective identity.
	 *
	 * @param string|string[] $to
	 * @param string|string[] $headers
	 * @param string|string[] $attachments
	 * @return true|\WP_Error
	 */
	public static function send_if_identity_matches(
		string $identity_id,
		string $expected_email,
		string $expected_name,
		string|array $to,
		string $subject,
		string $message,
		string|array $headers = [],
		string|array $attachments = []
	): true|\WP_Error {
		$identity_id = sanitize_key( $identity_id );
		$identity = SenderIdentityRegistry::get( $identity_id );
		$expected_email = sanitize_email( $expected_email );
		$expected_name = sanitize_text_field( $expected_name );

		if ( null === $identity || ! is_email( $expected_email ) ) {
			return new \WP_Error( 'cb_core_mail_sender_identity_unavailable' );
		}

		$current_email = sanitize_email( (string) ( $identity['email'] ?? '' ) );
		$current_name = sanitize_text_field( (string) ( $identity['name'] ?? '' ) );
		if (
			0 !== strcasecmp( $current_email, $expected_email )
			|| ! hash_equals( $current_name, $expected_name )
		) {
			return new \WP_Error( 'cb_core_mail_sender_identity_changed' );
		}

		$sent = self::send_with_identity(
			$identity_id,
			$identity,
			$to,
			$subject,
			$message,
			$headers,
			$attachments,
			true
		);

		return $sent ? true : new \WP_Error( 'cb_core_mail_send_failed' );
	}

	/**
	 * @param array<string,mixed> $identity
	 * @param string|string[] $to
	 * @param string|string[] $headers
	 * @param string|string[] $attachments
	 */
	private static function send_with_identity(
		string $scoped_id,
		array $identity,
		string|array $to,
		string $subject,
		string $message,
		string|array $headers,
		string|array $attachments,
		bool $resolved_context
	): bool {
		$headers = self::with_from_header( $headers, $identity );

		if ( $resolved_context ) {
			if ( ! SenderContext::push_resolved( $scoped_id, $identity ) ) {
				return false;
			}
		} else {
			SenderContext::push( $scoped_id );
		}

		try {
			return wp_mail( $to, $subject, $message, $headers, $attachments );
		} finally {
			SenderContext::pop();
		}
	}

	/**
	 * Replace any caller-provided From header with the Base-resolved identity.
	 *
	 * @param string|string[] $headers
	 * @param array{email:string,name:string} $identity
	 * @return string[]
	 */
	private static function with_from_header( string|array $headers, array $identity ): array {
		if ( is_string( $headers ) ) {
			$headers = '' === trim( $headers ) ? [] : ( preg_split( '/\r?\n/', trim( $headers ) ) ?: [] );
		}

		$out = [];
		foreach ( $headers as $key => $line ) {
			if ( is_string( $key ) && is_string( $line ) && ! str_contains( $line, ':' ) ) {
				$line = $key . ': ' . $line;
			}
			if ( ! is_string( $line ) ) {
				continue;
			}
			if ( 1 === preg_match( '/^\s*from\s*:/i', $line ) ) {
				continue;
			}
			$out[] = $line;
		}

		$email = sanitize_email( (string) ( $identity['email'] ?? '' ) );
		$name  = sanitize_text_field( (string) ( $identity['name'] ?? '' ) );
		if ( is_email( $email ) ) {
			$out[] = '' !== $name
				? sprintf( 'From: %s <%s>', $name, $email )
				: 'From: ' . $email;
		}

		return $out;
	}
}
