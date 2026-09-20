<?php
declare(strict_types=1);

namespace CB\Core\Profiles;

defined( 'ABSPATH' ) || exit;

final class ApplyLock {
	private const OPTION = 'cb_core_profiles_apply_lock';
	private const STALE_TTL = 1800;

	public static function acquire( string $actor ): string {
		$token = bin2hex( random_bytes( 16 ) );
		$data = [
			'token'        => $token,
			'actor'        => sanitize_text_field( $actor ),
			'acquired_at'  => time(),
			'refreshed_at' => time(),
		];
		if ( add_option( self::OPTION, $data, '', false ) ) {
			return $token;
		}
		$current_raw = self::read_raw();
		if ( null === $current_raw ) {
			if ( add_option( self::OPTION, $data, '', false ) ) {
				return $token;
			}
			throw new \RuntimeException( __( 'Another profile apply is already running.', 'core-blueprint' ) );
		}
		$current = maybe_unserialize( $current_raw );
		$current = is_array( $current ) ? $current : [];
		$reference = (int) ( $current['refreshed_at'] ?? $current['acquired_at'] ?? 0 );
		$age = time() - $reference;
		if ( $age > self::STALE_TTL && self::replace_raw( $current_raw, maybe_serialize( $data ) ) ) {
			return $token;
		}
		throw new \RuntimeException( __( 'Another profile apply is already running.', 'core-blueprint' ) );
	}

	public static function refresh( string $token ): bool {
		$current_raw = self::read_raw();
		if ( null === $current_raw ) {
			return false;
		}
		$current = maybe_unserialize( $current_raw );
		if ( ! is_array( $current ) || '' === $token || $token !== (string) ( $current['token'] ?? '' ) ) {
			return false;
		}
		$current['refreshed_at'] = time();
		$new_raw = maybe_serialize( $current );
		if ( $new_raw === $current_raw ) {
			return true;
		}
		if ( self::replace_raw( $current_raw, $new_raw ) ) {
			return true;
		}
		return self::is_owned_by( $token );
	}

	public static function is_owned_by( string $token ): bool {
		$current = get_option( self::OPTION, [] );
		return is_array( $current ) && '' !== $token && $token === (string) ( $current['token'] ?? '' );
	}

	public static function release( string $token ): void {
		global $wpdb;
		$current_raw = self::read_raw();
		if ( null === $current_raw ) {
			return;
		}
		$current = maybe_unserialize( $current_raw );
		if ( ! is_array( $current ) || '' === $token || $token !== (string) ( $current['token'] ?? '' ) ) {
			return;
		}
		$affected = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			self::OPTION,
			$current_raw
		) );
		if ( 1 === $affected ) {
			wp_cache_delete( self::OPTION, 'options' );
		}
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	private static function read_raw(): ?string {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			self::OPTION
		) );
		return is_string( $raw ) ? $raw : null;
	}

	private static function replace_raw( string $expected_raw, string $new_raw ): bool {
		global $wpdb;
		$affected = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			$new_raw,
			self::OPTION,
			$expected_raw
		) );
		if ( 1 !== $affected ) {
			return false;
		}
		wp_cache_delete( self::OPTION, 'options' );
		return true;
	}
}
