<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Site-private same-database application recovery snapshot.
 *
 * This is NOT an independent disaster-recovery backup. No export, restore,
 * automatic cleanup, endpoint or hook is defined here. An operator-controlled
 * recovery uses verified source material after separately approved review.
 *
 * @internal
 */
final class MigrationBackup {
	public const OPTION = 'cb_core_design_docs_migration_backup_v1';
	private const VERSION = 1;

	/** @param array<string,mixed> $inventory */
	public static function ensure( array $inventory ): array {
		if ( 'ok' !== ( $inventory['status'] ?? '' ) ) {
			return [ 'status' => 'invalid' ];
		}
		$existing = self::read_verified();
		if ( 'found' === $existing['status'] ) {
			return $existing['fingerprint'] === $inventory['fingerprint']
				&& $existing['target_hash'] === $inventory['target_hash']
				&& $existing['source'] === $inventory['source']
					? [ 'status' => 'ready' ]
					: [ 'status' => 'source_changed' ];
		}
		if ( 'missing' !== $existing['status'] ) {
			return [ 'status' => $existing['status'] ];
		}
		$source = [];
		foreach ( [ MigrationInventory::SETTINGS_OPTION, \CoreBlueprint\Core\Mail\Designer\TemplateRepository::OPTION ] as $name ) {
			$entry = $inventory['source'][$name] ?? null;
			$source[$name] = null === $entry ? null : [
				'option_name' => $entry['option_name'],
				'autoload' => $entry['autoload'],
				'raw_base64' => base64_encode( $entry['option_value'] ),
			];
		}
		$payload = [
			'version' => self::VERSION,
			'blog_id' => $inventory['blog_id'],
			'options_table' => $inventory['options_table'],
			'fingerprint' => $inventory['fingerprint'],
			'target_hash' => $inventory['target_hash'],
			'source' => $source,
		];
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || '' === $json ) {
			return [ 'status' => 'invalid' ];
		}
		$envelope = wp_json_encode( [
			'payload' => $payload, 'sha256' => hash( 'sha256', $json ),
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $envelope ) || strlen( $envelope ) > 44040192 ) {
			return [ 'status' => 'invalid' ];
		}

		global $wpdb;
		$affected = $wpdb->query( $wpdb->prepare(
			'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
			$wpdb->options, self::OPTION, $envelope, 'no'
		) );
		if ( 1 !== $affected ) {
			$after = self::read_verified();
			return [ 'status' => 'found' === $after['status'] ? 'conflict' : 'error' ];
		}
		$after = self::read_verified();
		return 'found' === $after['status']
			&& $after['fingerprint'] === $inventory['fingerprint']
			&& $after['target_hash'] === $inventory['target_hash']
			&& $after['source'] === $inventory['source']
				? [ 'status' => 'ready' ]
				: [ 'status' => 'uncertain' ];
	}

	/**
	 * Private verified recovery material. No direct database restoration:
	 * rollback requires an independently authorized, site-scoped operation.
	 *
	 * @return array<string,mixed>
	 */
	public static function read_verified(): array {
		$row = MigrationInventory::read_option( self::OPTION );
		if ( 'ok' !== $row['status'] ) {
			return [ 'status' => 'error' ];
		}
		if ( null === $row['option'] ) {
			return [ 'status' => 'missing' ];
		}
		if ( ! in_array( $row['option']['autoload'], [ 'no', 'off' ], true ) ) {
			return [ 'status' => 'corrupt' ];
		}
		try {
			$envelope = json_decode( $row['option']['option_value'], true, 32, JSON_THROW_ON_ERROR );
			$payload = $envelope['payload'] ?? null;
			$checksum = $envelope['sha256'] ?? null;
			if ( ! is_array( $payload ) || ! is_string( $checksum )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $checksum ) ) {
				return [ 'status' => 'corrupt' ];
			}
			$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $json ) || ! hash_equals( hash( 'sha256', $json ), $checksum ) ) {
				return [ 'status' => 'corrupt' ];
			}
			global $wpdb;
			if ( self::VERSION !== ( $payload['version'] ?? null )
				|| (int) get_current_blog_id() !== ( $payload['blog_id'] ?? null )
				|| $wpdb->options !== ( $payload['options_table'] ?? null )
				|| ! is_string( $payload['fingerprint'] ?? null )
				|| ! is_string( $payload['target_hash'] ?? null )
				|| ! is_array( $payload['source'] ?? null ) ) {
				return [ 'status' => 'corrupt' ];
			}
			$source = [];
			foreach ( [ \CoreBlueprint\Core\Mail\Designer\TemplateRepository::OPTION, MigrationInventory::SETTINGS_OPTION ] as $name ) {
				if ( ! array_key_exists( $name, $payload['source'] ) ) {
					return [ 'status' => 'corrupt' ];
				}
				$entry = $payload['source'][$name];
				if ( null === $entry ) {
					$source[$name] = null;
					continue;
				}
				if ( ! is_array( $entry ) || ( $entry['option_name'] ?? null ) !== $name
					|| ! is_string( $entry['autoload'] ?? null )
					|| ! is_string( $entry['raw_base64'] ?? null ) ) {
					return [ 'status' => 'corrupt' ];
				}
				$bytes = base64_decode( $entry['raw_base64'], true );
				if ( ! is_string( $bytes ) || base64_encode( $bytes ) !== $entry['raw_base64'] ) {
					return [ 'status' => 'corrupt' ];
				}
				$source[$name] = [
					'option_name' => $name, 'option_value' => $bytes, 'autoload' => $entry['autoload'],
				];
			}
			if ( $payload['fingerprint'] !== MigrationInventory::fingerprint( $source ) ) {
				return [ 'status' => 'corrupt' ];
			}
			return [
				'status' => 'found',
				'fingerprint' => $payload['fingerprint'],
				'target_hash' => $payload['target_hash'],
				'source' => $source,
			];
		} catch ( \Throwable $error ) {
			return [ 'status' => 'corrupt' ];
		}
	}

	private function __construct() {}
}
