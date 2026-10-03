<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Quarantine;

use CoreBlueprint\Core\Integrity\Storage\ChunkedOptionStore;

use function array_values;
use function current_time;
use function get_current_user_id;
use function is_array;
use function sanitize_key;

/**
 * Packet-safe persistence for the Scanner Quarantine Workspace.
 *
 * Deliberately uses a non-cb_core_integrity_* option key so uninstall does not
 * silently destroy quarantined evidence. Reinstalling Core Blueprint can pick
 * the workspace back up.
 */
final class Repository {
	private const OPTION = 'cb_core_quarantine_workspace';

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		$value = ChunkedOptionStore::get( self::OPTION, [] );
		return is_array( $value ) ? $value : [];
	}

	/** @return array<int,array<string,mixed>> */
	public static function items(): array {
		$items = array_values( self::all() );
		usort( $items, static fn( array $a, array $b ): int => strcmp( (string) ( $b['updated_at'] ?? $b['quarantined_at'] ?? '' ), (string) ( $a['updated_at'] ?? $a['quarantined_at'] ?? '' ) ) );
		return $items;
	}

	public static function get( string $id ): ?array {
		$id = sanitize_key( $id );
		$all = self::all();
		$item = $all[ $id ] ?? null;
		return is_array( $item ) ? $item : null;
	}

	public static function save( array $item ): bool {
		$id = sanitize_key( (string) ( $item['id'] ?? '' ) );
		if ( '' === $id ) {
			return false;
		}
		$all = self::all();
		$all[ $id ] = $item;
		return ChunkedOptionStore::set( self::OPTION, $all );
	}

	public static function delete( string $id ): bool {
		$id = sanitize_key( $id );
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return true;
		}
		unset( $all[ $id ] );
		return ChunkedOptionStore::set( self::OPTION, $all );
	}

	public static function append_event( array $item, string $action, array $context = [] ): array {
		$events = is_array( $item['events'] ?? null ) ? $item['events'] : [];
		$events[] = [
			'action'     => sanitize_key( $action ),
			'at'         => current_time( 'mysql' ),
			'by_user_id' => get_current_user_id(),
			'context'    => $context,
		];
		$item['events'] = $events;
		$item['updated_at'] = current_time( 'mysql' );
		return $item;
	}

	public static function open_count(): int {
		$count = 0;
		foreach ( self::all() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$status = (string) ( $item['status'] ?? '' );
			if ( ! in_array( $status, [ 'restored', 'deleted' ], true ) ) {
				$count++;
			}
		}
		return $count;
	}
}
