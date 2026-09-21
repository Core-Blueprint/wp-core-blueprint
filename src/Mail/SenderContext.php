<?php
declare(strict_types=1);
/**
 * Request-local sender identity context for Core Blueprint Mail.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Mail;

defined( 'ABSPATH' ) || exit;

final class SenderContext {

	/** @var array<int,array{id:string,identity:null|array<string,string>}> */
	private static array $stack = [];

	public static function push( string $identity_id ): void {
		self::$stack[] = [
			'id'       => sanitize_key( $identity_id ),
			'identity' => null,
		];
	}

	/**
	 * Push one already-resolved registered identity for the duration of a send.
	 *
	 * @param array<string,string> $identity
	 */
	public static function push_resolved( string $identity_id, array $identity ): bool {
		$identity_id = sanitize_key( $identity_id );
		$current = SenderIdentityRegistry::get( $identity_id );
		if ( null === $current ) {
			return false;
		}

		$current_email = sanitize_email( (string) ( $current['email'] ?? '' ) );
		$current_name = sanitize_text_field( (string) ( $current['name'] ?? '' ) );
		$snapshot_email = sanitize_email( (string) ( $identity['email'] ?? '' ) );
		$snapshot_name = sanitize_text_field( (string) ( $identity['name'] ?? '' ) );
		if (
			! is_email( $snapshot_email )
			|| 0 !== strcasecmp( $current_email, $snapshot_email )
			|| ! hash_equals( $current_name, $snapshot_name )
		) {
			return false;
		}

		self::$stack[] = [
			'id'       => $identity_id,
			'identity' => $current,
		];
		return true;
	}

	public static function pop(): void {
		array_pop( self::$stack );
	}

	public static function current(): string {
		if ( empty( self::$stack ) ) {
			return '';
		}
		$current = end( self::$stack );
		return is_array( $current ) ? (string) ( $current['id'] ?? '' ) : '';
	}

	/** @return null|array<string,string> */
	public static function current_resolved(): ?array {
		if ( empty( self::$stack ) ) {
			return null;
		}
		$current = end( self::$stack );
		$identity = is_array( $current ) ? ( $current['identity'] ?? null ) : null;
		return is_array( $identity ) ? $identity : null;
	}
}
