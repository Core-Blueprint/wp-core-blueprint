<?php
declare(strict_types=1);
/**
 * Normalises WordPress wp_mail() arguments for provider adapters and logging.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail;

defined( 'ABSPATH' ) || exit;

final class Message {

	public static function from_atts( array $atts ): array {
		$settings = Settings::all();
		$headers  = self::parse_headers( $atts['headers'] ?? '' );
		$identity = SenderIdentityRegistry::current();

		$from_email = (string) ( $headers['from']['email'] ?? '' );
		$from_name  = (string) ( $headers['from']['name'] ?? '' );

		if ( null !== $identity ) {
			$from_email = $identity['email'];
			$from_name  = $identity['name'];
		} else {
			if ( ! empty( $settings['force_from_email'] ) || ! is_email( $from_email ) ) {
				$from_email = sanitize_email( (string) $settings['from_email'] );
			}
			if ( ! empty( $settings['force_from_name'] ) || '' === $from_name ) {
				$from_name = sanitize_text_field( (string) $settings['from_name'] );
			}
		}

		// pre_wp_mail runs before WordPress applies these sender filters. Apply
		// them explicitly so Brevo preserves the same extension contract as the
		// normal wp_mail() path. Runtime's force filters, when enabled, preserve
		// an active registered sender identity and otherwise enforce defaults.
		$from_email = sanitize_email( (string) apply_filters( 'wp_mail_from', $from_email ) );
		$from_name  = sanitize_text_field( (string) apply_filters( 'wp_mail_from_name', $from_name ) );

		// Respect WordPress' content-type filter for mailers (WooCommerce is a
		// common example) that set HTML via a filter rather than a raw header.
		$content_type = (string) ( $headers['content_type'] ?? '' );
		if ( '' === $content_type ) {
			$content_type = (string) apply_filters( 'wp_mail_content_type', 'text/plain' );
		}

		$attachments = self::files( $atts['attachments'] ?? [] );
		$embeds      = self::files( $atts['embeds'] ?? [] );

		return [
			'to'           => self::addresses( $atts['to'] ?? [] ),
			'cc'           => $headers['cc'],
			'bcc'          => $headers['bcc'],
			'reply_to'     => $headers['reply_to'],
			'from_email'   => $from_email,
			'from_name'    => $from_name,
			'subject'      => sanitize_text_field( (string) ( $atts['subject'] ?? '' ) ),
			'message'      => (string) ( $atts['message'] ?? '' ),
			'content_type' => strtolower( $content_type ),
			'attachments'  => $attachments,
			'embeds'       => $embeds,
		];
	}

	private static function parse_headers( mixed $raw ): array {
		$out = [
			'cc'           => [],
			'bcc'          => [],
			'reply_to'     => [],
			'from'         => [],
			'content_type' => '',
		];

		if ( is_string( $raw ) ) {
			$lines = preg_split( '/\r?\n/', trim( $raw ) ) ?: [];
		} elseif ( is_array( $raw ) ) {
			$lines = $raw;
		} else {
			$lines = [];
		}

		foreach ( $lines as $header_key => $line ) {
			if ( is_string( $header_key ) && is_string( $line ) && ! str_contains( $line, ':' ) ) {
				$line = $header_key . ': ' . $line;
			}
			if ( ! is_string( $line ) || ! str_contains( $line, ':' ) ) {
				continue;
			}
			[ $name, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$name = strtolower( $name );

			switch ( $name ) {
				case 'cc':
					$out['cc'] = array_merge( $out['cc'], self::addresses( $value ) );
					break;
				case 'bcc':
					$out['bcc'] = array_merge( $out['bcc'], self::addresses( $value ) );
					break;
				case 'reply-to':
					$out['reply_to'] = array_merge( $out['reply_to'], self::addresses( $value ) );
					break;
				case 'from':
					$from = self::addresses( $value );
					$out['from'] = $from[0] ?? [];
					break;
				case 'content-type':
					$parts = explode( ';', $value );
					$out['content_type'] = strtolower( trim( (string) ( $parts[0] ?? '' ) ) );
					break;
			}
		}

		return $out;
	}

	/** @return array<int,array{email:string,name:string}> */
	public static function addresses( mixed $raw ): array {
		$items = is_array( $raw ) ? $raw : str_getcsv( (string) $raw, ',', '"', '\\' );
		$items = is_array( $items ) ? $items : [];
		$out   = [];

		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['email'] ) ) {
				$email = sanitize_email( (string) $item['email'] );
				$name  = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
			} else {
				$item = trim( (string) $item );
				$email = $item;
				$name  = '';
				if ( preg_match( '/^(.*?)<([^>]+)>$/', $item, $m ) ) {
					$name  = trim( trim( $m[1] ), " \t\n\r\0\x0B\"'" );
					$email = trim( $m[2] );
				}
				$email = sanitize_email( $email );
				$name  = sanitize_text_field( $name );
			}

			if ( is_email( $email ) ) {
				$out[] = [ 'email' => $email, 'name' => $name ];
			}
		}

		return $out;
	}

	/** @return string[] */
	private static function files( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = '' === trim( $raw ) ? [] : preg_split( '/\r?\n/', trim( $raw ) );
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( $raw as $path ) {
			if ( is_string( $path ) && '' !== trim( $path ) ) {
				$out[] = trim( $path );
			}
		}
		return $out;
	}
}
