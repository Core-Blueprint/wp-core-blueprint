<?php
declare(strict_types=1);
/**
 * Shared role/capability catalog adapter for Object Picker consumers.
 *
 * Owns only presentation/search mapping. Feature-specific AJAX actions,
 * authorization, nonces and policy semantics stay with each consumer.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\UI;

use CB\Core\Permissions\CapabilityCatalog;

defined( 'ABSPATH' ) || exit;

final class RoleCapabilityPicker {

	/** @param list<string> $roles @return list<array{id:string,label:string,meta:string}> */
	public static function role_items( array $roles ): array {
		$registry = (array) wp_roles()->roles;
		$items    = [];

		foreach ( $roles as $role ) {
			if ( ! is_string( $role ) || '' === $role ) {
				continue;
			}

			$details = isset( $registry[ $role ] ) && is_array( $registry[ $role ] ) ? $registry[ $role ] : [];
			$name    = isset( $details['name'] ) && is_string( $details['name'] )
				? translate_user_role( $details['name'] )
				: $role;

			$items[] = [
				'id'    => $role,
				'label' => '' !== $name ? $name : $role,
				'meta'  => $name !== $role ? $role : '',
			];
		}

		return $items;
	}

	/** @param list<string> $capabilities @return list<array{id:string,label:string,meta:string}> */
	public static function capability_items( array $capabilities ): array {
		$catalog = self::capability_catalog();
		$items   = [];

		foreach ( $capabilities as $capability ) {
			if ( ! is_string( $capability ) || '' === $capability ) {
				continue;
			}

			$entry = isset( $catalog[ $capability ] ) && is_array( $catalog[ $capability ] ) ? $catalog[ $capability ] : [];
			$label = isset( $entry['label'] ) && is_string( $entry['label'] ) && '' !== $entry['label']
				? $entry['label']
				: $capability;

			$items[] = [
				'id'    => $capability,
				'label' => $label,
				'meta'  => $label !== $capability ? $capability : '',
			];
		}

		return $items;
	}

	/** @return list<array{id:string,label:string,meta:string}> */
	public static function search_roles( string $search ): array {
		$search = trim( $search );
		if ( strlen( $search ) < 2 ) {
			return [];
		}

		$items = [];
		foreach ( (array) wp_roles()->roles as $slug => $details ) {
			if ( ! is_string( $slug ) || ! is_array( $details ) ) {
				continue;
			}

			$name = isset( $details['name'] ) && is_string( $details['name'] )
				? translate_user_role( $details['name'] )
				: $slug;

			if ( false === mb_stripos( $slug . ' ' . $name, $search ) ) {
				continue;
			}

			$items[] = [
				'id'    => $slug,
				'label' => '' !== $name ? $name : $slug,
				'meta'  => $name !== $slug ? $slug : '',
			];
		}

		usort( $items, static fn( array $a, array $b ): int => strnatcasecmp( (string) $a['label'], (string) $b['label'] ) );
		return array_slice( $items, 0, 50 );
	}

	/** @return list<array{id:string,label:string,meta:string}> */
	public static function search_capabilities( string $search ): array {
		$search = trim( $search );
		if ( strlen( $search ) < 2 ) {
			return [];
		}

		$items = [];
		foreach ( self::capability_catalog() as $capability => $entry ) {
			if ( ! is_string( $capability ) || ! is_array( $entry ) ) {
				continue;
			}

			$label  = isset( $entry['label'] ) && is_string( $entry['label'] ) ? $entry['label'] : $capability;
			$group  = isset( $entry['group'] ) && is_string( $entry['group'] ) ? $entry['group'] : '';
			$source = isset( $entry['source'] ) && is_string( $entry['source'] ) ? $entry['source'] : '';

			if ( false === mb_stripos( $capability . ' ' . $label . ' ' . $group . ' ' . $source, $search ) ) {
				continue;
			}

			$items[] = [
				'id'    => $capability,
				'label' => '' !== $label ? $label : $capability,
				'meta'  => $label !== $capability ? $capability : '',
			];

			if ( count( $items ) >= 50 ) {
				break;
			}
		}

		return $items;
	}

	/** @return array<string,array<string,mixed>> */
	private static function capability_catalog(): array {
		static $catalog = null;
		if ( null === $catalog ) {
			$catalog = CapabilityCatalog::all();
		}
		return $catalog;
	}

	private function __construct() {}
}
