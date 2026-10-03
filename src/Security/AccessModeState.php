<?php
declare(strict_types=1);
/**
 * Canonical Access Mode state, validation and persistence.
 *
 * This class is internal to Base. AccessMode remains the stable public facade.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Security;

use DateTimeImmutable;
use DateTimeZone;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class AccessModeState {

	public static function current(): string {
		$mode = (string) get_option( AccessMode::OPTION_KEY, AccessMode::MODE_PUBLIC );
		return in_array( $mode, AccessMode::modes(), true ) ? $mode : AccessMode::MODE_PUBLIC;
	}

	/** @return array{schema_version:int,coming_soon_page_id:int,coming_soon_indexable:bool,maintenance_page_id:int,maintenance_until_date:string,maintenance_until_time:string} */
	public static function config(): array {
		$defaults = self::default_config();
		$stored   = get_option( AccessMode::CONFIG_OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$config = array_merge( $defaults, $stored );
		$config['schema_version']            = AccessMode::CONFIG_SCHEMA;
		$config['coming_soon_page_id']       = absint( $config['coming_soon_page_id'] ?? 0 );
		$config['coming_soon_indexable']     = ! empty( $config['coming_soon_indexable'] );
		$config['maintenance_page_id']       = absint( $config['maintenance_page_id'] ?? 0 );
		$config['maintenance_until_date']    = self::sanitize_date( (string) ( $config['maintenance_until_date'] ?? '' ) );
		$config['maintenance_until_time']    = self::sanitize_time( (string) ( $config['maintenance_until_time'] ?? '' ) );
		return $config;
	}

	/** @return array{schema_version:int,coming_soon_page_id:int,coming_soon_indexable:bool,maintenance_page_id:int,maintenance_until_date:string,maintenance_until_time:string} */
	public static function default_config(): array {
		return [
			'schema_version'         => AccessMode::CONFIG_SCHEMA,
			'coming_soon_page_id'    => 0,
			'coming_soon_indexable'  => true,
			'maintenance_page_id'    => 0,
			'maintenance_until_date' => '',
			'maintenance_until_time' => '',
		];
	}

	/** @param array<string,mixed> $config */
	public static function validate_config_for_mode( string $mode, array $config ): string {
		if ( AccessMode::MODE_COMING_SOON === $mode && ! self::valid_landing_page( (int) $config['coming_soon_page_id'] ) ) {
			return __( 'Choose a published, non-password-protected page before activating Coming Soon.', 'core-blueprint' );
		}
		if ( AccessMode::MODE_MAINTENANCE === $mode && ! self::valid_landing_page( (int) $config['maintenance_page_id'] ) ) {
			return __( 'Choose a published, non-password-protected page before activating Maintenance.', 'core-blueprint' );
		}

		$date = (string) ( $config['maintenance_until_date'] ?? '' );
		$time = (string) ( $config['maintenance_until_time'] ?? '' );
		if ( ( '' === $date ) !== ( '' === $time ) ) {
			return __( 'Expected back online needs both a date and a time, or neither.', 'core-blueprint' );
		}
		if ( '' !== $date && ! self::maintenance_until_datetime( $date, $time ) ) {
			return __( 'Enter a valid expected return date and time.', 'core-blueprint' );
		}
		if ( AccessMode::MODE_MAINTENANCE === $mode && '' !== $date ) {
			$at = self::maintenance_until_datetime( $date, $time );
			if ( ! $at || $at->getTimestamp() <= time() ) {
				return __( 'Expected back online must be in the future when Maintenance is activated.', 'core-blueprint' );
			}
		}

		return '';
	}

	public static function valid_landing_page( int $page_id ): ?WP_Post {
		if ( $page_id <= 0 ) {
			return null;
		}
		$page = get_post( $page_id );
		if ( ! $page instanceof WP_Post || 'page' !== $page->post_type || 'publish' !== $page->post_status || '' !== (string) $page->post_password ) {
			return null;
		}
		return $page;
	}

	public static function persist_config( array $config ): bool {
		return self::persist_option( AccessMode::CONFIG_OPTION_KEY, $config );
	}

	public static function persist_mode( string $mode ): bool {
		if ( ! in_array( $mode, AccessMode::modes(), true ) ) {
			return false;
		}
		return self::persist_option( AccessMode::OPTION_KEY, $mode );
	}

	public static function sanitize_date( string $value ): string {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return '';
		}
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	public static function sanitize_time( string $value ): string {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return '';
		}
		return 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
	}

	public static function maintenance_until_datetime( string $date, string $time ): ?DateTimeImmutable {
		if ( '' === $date || '' === $time ) {
			return null;
		}
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$at       = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, $timezone );
		if ( ! $at || $at->format( 'Y-m-d H:i' ) !== $date . ' ' . $time ) {
			return null;
		}
		return $at;
	}

	private static function persist_option( string $key, mixed $value ): bool {
		$current = get_option( $key, null );
		if ( $current === $value ) {
			return true;
		}
		if ( update_option( $key, $value, false ) ) {
			return true;
		}
		return get_option( $key, null ) === $value;
	}
}
