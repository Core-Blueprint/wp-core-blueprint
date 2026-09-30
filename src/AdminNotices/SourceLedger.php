<?php
declare(strict_types=1);
/**
 * Bounded metadata ledger for observed Admin Notices sources.
 *
 * The ledger stores source identity and observation metadata only. It never
 * stores rendered notice HTML, message bodies, action URLs, nonces or payloads.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\AdminNotices;

defined( 'ABSPATH' ) || exit;

final class SourceLedger {

	public const OPTION  = 'cb_core_admin_notice_sources';
	public const VERSION = 1;

	private const MAX_SOURCES = 300;
	private const MAX_UNKNOWN = 50;
	private const LABEL_BYTES = 160;
	private const SEEN_REFRESH_SECONDS = DAY_IN_SECONDS;

	/** @return array{version:int,sources:array<string,array<string,mixed>>} */
	public static function defaults(): array {
		return [
			'version' => self::VERSION,
			'sources' => [],
		];
	}

	/** @return array{version:int,sources:array<string,array<string,mixed>>} */
	public static function get(): array {
		$raw = get_option( self::OPTION, null );
		if ( null === $raw || ! is_array( $raw ) ) {
			return self::defaults();
		}
		return self::normalize( $raw );
	}

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		return self::get()['sources'];
	}

	/** @return array<string,mixed>|null */
	public static function source( string $source_id ): ?array {
		$sources = self::all();
		return isset( $sources[ $source_id ] ) ? $sources[ $source_id ] : null;
	}

	/**
	 * Observe callbacks on one notice hook.
	 *
	 * @param list<array{priority:int,callback:mixed,source:array<string,mixed>}> $entries
	 */
	public static function observe_hook( string $hook, array $entries, ?int $now = null ): bool {
		if ( ! in_array( $hook, Discovery::HOOKS, true ) ) {
			return false;
		}

		$now ??= time();
		$state = self::get();
		$before = $state;
		$unknown_count = 0;
		foreach ( $state['sources'] as $source ) {
			if ( SourceResolver::KIND_UNKNOWN === (string) ( $source['kind'] ?? '' ) ) {
				$unknown_count++;
			}
		}

		$observed = [];
		foreach ( $entries as $entry ) {
			$source = is_array( $entry['source'] ?? null ) ? $entry['source'] : [];
			$id = isset( $source['id'] ) && is_string( $source['id'] ) ? $source['id'] : '';
			if ( ! self::valid_source_id( $id ) ) {
				continue;
			}

			if ( ! isset( $observed[ $id ] ) ) {
				$observed[ $id ] = [
					'source' => $source,
					'count'  => 0,
				];
			}
			$observed[ $id ]['count']++;
		}

		foreach ( $observed as $id => $observation ) {
			$source = $observation['source'];
			$kind = self::normalize_kind( (string) ( $source['kind'] ?? '' ) );
			$existing = $state['sources'][ $id ] ?? null;

			if ( ! is_array( $existing ) ) {
				if ( count( $state['sources'] ) >= self::MAX_SOURCES ) {
					continue;
				}
				if ( SourceResolver::KIND_UNKNOWN === $kind && $unknown_count >= self::MAX_UNKNOWN ) {
					continue;
				}
				if ( SourceResolver::KIND_UNKNOWN === $kind ) {
					$unknown_count++;
				}

				$state['sources'][ $id ] = [
					'id'             => $id,
					'label'          => self::normalize_label( (string) ( $source['label'] ?? $id ) ),
					'kind'           => $kind,
					'manageable'     => ! empty( $source['manageable'] ),
					'protected'      => ! empty( $source['protected'] ),
					'hooks'          => [ $hook ],
					'callback_count' => min( 1000, max( 1, (int) $observation['count'] ) ),
					'first_seen'     => max( 0, $now ),
					'last_seen'      => max( 0, $now ),
				];
				continue;
			}

			$hooks = array_values( array_unique( array_merge( (array) ( $existing['hooks'] ?? [] ), [ $hook ] ) ) );
			sort( $hooks, SORT_STRING );

			$next = [
				'id'             => $id,
				'label'          => self::normalize_label( (string) ( $source['label'] ?? $existing['label'] ?? $id ) ),
				'kind'           => $kind,
				'manageable'     => ! empty( $source['manageable'] ),
				'protected'      => ! empty( $source['protected'] ),
				'hooks'          => $hooks,
				'callback_count' => max(
					(int) ( $existing['callback_count'] ?? 0 ),
					min( 1000, max( 1, (int) $observation['count'] ) )
				),
				'first_seen'     => max( 0, (int) ( $existing['first_seen'] ?? $now ) ),
				'last_seen'      => max( 0, (int) ( $existing['last_seen'] ?? 0 ) ),
			];

			$metadata_changed = self::without_last_seen( $existing ) !== self::without_last_seen( $next );
			if ( $metadata_changed || $next['last_seen'] <= ( $now - self::SEEN_REFRESH_SECONDS ) ) {
				$next['last_seen'] = max( 0, $now );
			}
			$state['sources'][ $id ] = $next;
		}

		ksort( $state['sources'], SORT_STRING );
		if ( $before === $state ) {
			return false;
		}

		update_option( self::OPTION, self::normalize( $state ), false );
		return self::normalize( $state ) === self::get();
	}

	public static function clear(): bool {
		if ( false === get_option( self::OPTION, false ) ) {
			return true;
		}
		return delete_option( self::OPTION );
	}

	/** @return array{version:int,sources:array<string,array<string,mixed>>} */
	private static function normalize( array $raw ): array {
		if ( self::VERSION !== (int) ( $raw['version'] ?? 0 ) || ! is_array( $raw['sources'] ?? null ) ) {
			return self::defaults();
		}

		$sources = [];
		$unknown_count = 0;
		foreach ( $raw['sources'] as $id => $row ) {
			$id = is_string( $id ) ? $id : '';
			if ( ! self::valid_source_id( $id ) || ! is_array( $row ) ) {
				continue;
			}
			$kind = self::normalize_kind( (string) ( $row['kind'] ?? '' ) );
			if ( SourceResolver::KIND_UNKNOWN === $kind && ++$unknown_count > self::MAX_UNKNOWN ) {
				continue;
			}

			$hooks = [];
			foreach ( is_array( $row['hooks'] ?? null ) ? $row['hooks'] : [] as $hook ) {
				if ( is_string( $hook ) && in_array( $hook, Discovery::HOOKS, true ) ) {
					$hooks[ $hook ] = $hook;
				}
			}
			$hooks = array_values( $hooks );
			sort( $hooks, SORT_STRING );

			$sources[ $id ] = [
				'id'             => $id,
				'label'          => self::normalize_label( (string) ( $row['label'] ?? $id ) ),
				'kind'           => $kind,
				'manageable'     => ! empty( $row['manageable'] ) && SourceResolver::KIND_UNKNOWN !== $kind,
				'protected'      => ! empty( $row['protected'] ),
				'hooks'          => $hooks,
				'callback_count' => min( 1000, max( 0, (int) ( $row['callback_count'] ?? 0 ) ) ),
				'first_seen'     => max( 0, (int) ( $row['first_seen'] ?? 0 ) ),
				'last_seen'      => max( 0, (int) ( $row['last_seen'] ?? 0 ) ),
			];

			if ( count( $sources ) >= self::MAX_SOURCES ) {
				break;
			}
		}

		ksort( $sources, SORT_STRING );
		return [
			'version' => self::VERSION,
			'sources' => $sources,
		];
	}

	private static function valid_source_id( string $id ): bool {
		if ( SourceResolver::is_manageable_id( $id ) ) {
			return true;
		}
		return 1 === preg_match( '/^unknown:[a-f0-9]{16}$/', $id );
	}

	private static function normalize_kind( string $kind ): string {
		return in_array(
			$kind,
			[
				SourceResolver::KIND_WORDPRESS,
				SourceResolver::KIND_PLUGIN,
				SourceResolver::KIND_MU_PLUGIN,
				SourceResolver::KIND_THEME,
				SourceResolver::KIND_UNKNOWN,
			],
			true
		) ? $kind : SourceResolver::KIND_UNKNOWN;
	}

	private static function normalize_label( string $label ): string {
		$label = trim( sanitize_text_field( $label ) );
		if ( function_exists( 'mb_strcut' ) ) {
			$label = mb_strcut( $label, 0, self::LABEL_BYTES, 'UTF-8' );
		} else {
			$label = substr( $label, 0, self::LABEL_BYTES );
		}
		return '' !== $label ? $label : 'Unknown source';
	}

	private static function without_last_seen( array $row ): array {
		unset( $row['last_seen'] );
		return $row;
	}

	private function __construct() {}
}
