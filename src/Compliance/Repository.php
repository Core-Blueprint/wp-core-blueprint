<?php
declare(strict_types=1);
/**
 * Persistence for site-owned compliance resource configuration.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Compliance;

defined( 'ABSPATH' ) || exit;

final class Repository {

	public const OPTION_ASSIGNMENTS = 'cb_core_compliance_resource_assignments';
	public const OPTION_CUSTOM      = 'cb_core_compliance_custom_resources';

	/**
	 * @return array<string,array{key:string,owner:string,id:string,label:string,description:string,custom:bool}>
	 */
	public static function custom_definitions(): array {
		$stored = get_option( self::OPTION_CUSTOM, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$definitions = [];
		foreach ( $stored as $key => $item ) {
			if ( ! is_string( $key ) || ! is_array( $item ) ) {
				continue;
			}
			$owner = isset( $item['owner'] ) && is_string( $item['owner'] ) ? sanitize_key( $item['owner'] ) : '';
			$id    = isset( $item['id'] ) && is_string( $item['id'] ) ? sanitize_key( $item['id'] ) : '';
			$label = isset( $item['label'] ) && is_string( $item['label'] ) ? trim( wp_strip_all_tags( $item['label'] ) ) : '';
			$description = isset( $item['description'] ) && is_string( $item['description'] )
				? trim( wp_strip_all_tags( $item['description'] ) )
				: '';

			if ( '' === $owner || '' === $id || '' === $label || $key !== ResourceRegistry::key( $owner, $id ) ) {
				continue;
			}
			if ( ! str_starts_with( $id, 'custom-' ) ) {
				continue;
			}

			$definitions[ $key ] = [
				'key'         => $key,
				'owner'       => $owner,
				'id'          => $id,
				'label'       => $label,
				'description' => $description,
				'custom'      => true,
			];
		}
		return $definitions;
	}

	/** Create one user-owned resource role and return its stable key. */
	public static function add_custom( string $owner, string $label, string $description = '' ): string {
		$owner       = sanitize_key( $owner );
		$label       = trim( sanitize_text_field( $label ) );
		$description = trim( sanitize_textarea_field( $description ) );
		if ( ! ResourceRegistry::owner_exists( $owner ) || '' === $label ) {
			return '';
		}

		$label       = self::limit_text( $label, 120 );
		$description = self::limit_text( $description, 500 );
		$stored      = get_option( self::OPTION_CUSTOM, [] );
		$stored      = is_array( $stored ) ? $stored : [];
		$software    = ResourceRegistry::software_definitions();

		do {
			$id  = 'custom-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 16 );
			$key = ResourceRegistry::key( $owner, $id );
		} while ( isset( $stored[ $key ] ) || isset( $software[ $key ] ) );

		$stored[ $key ] = [
			'owner'       => $owner,
			'id'          => $id,
			'label'       => $label,
			'description' => $description,
			'created_at'  => gmdate( 'c' ),
		];
		update_option( self::OPTION_CUSTOM, $stored, false );
		return $key;
	}

	/** Update a site-owned resource role without changing its stable key or assignment. */
	public static function update_custom( string $key, string $label, string $description = '' ): bool {
		$definitions = self::custom_definitions();
		if ( ! isset( $definitions[ $key ] ) ) {
			return false;
		}

		$label       = self::limit_text( trim( sanitize_text_field( $label ) ), 120 );
		$description = self::limit_text( trim( sanitize_textarea_field( $description ) ), 500 );
		if ( '' === $label ) {
			return false;
		}

		$stored = get_option( self::OPTION_CUSTOM, [] );
		if ( ! is_array( $stored ) || ! isset( $stored[ $key ] ) || ! is_array( $stored[ $key ] ) ) {
			return false;
		}

		$stored[ $key ]['label']       = $label;
		$stored[ $key ]['description'] = $description;
		$stored[ $key ]['updated_at']  = gmdate( 'c' );
		update_option( self::OPTION_CUSTOM, $stored, false );
		return true;
	}

	/** Limit sanitized text without requiring the optional mbstring extension. */
	private static function limit_text( string $value, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $value, 0, $length );
		}
		return substr( $value, 0, $length );
	}

	/** Delete a user-owned role and its assignments. Software roles are untouched. */
	public static function delete_custom( string $key ): bool {
		$definitions = self::custom_definitions();
		if ( ! isset( $definitions[ $key ] ) ) {
			return false;
		}

		$stored = get_option( self::OPTION_CUSTOM, [] );
		$stored = is_array( $stored ) ? $stored : [];
		unset( $stored[ $key ] );
		update_option( self::OPTION_CUSTOM, $stored, false );

		$assignments = self::assignments();
		unset( $assignments[ $key ] );
		update_option( self::OPTION_ASSIGNMENTS, $assignments, false );
		return true;
	}

	/**
	 * @return array<string,array{default:?array{type:string,object_id:int},locales:array<string,array{type:string,object_id:int}>}>
	 */
	public static function assignments(): array {
		$stored = get_option( self::OPTION_ASSIGNMENTS, [] );
		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * @return array{default:?array{type:string,object_id:int},locales:array<string,array{type:string,object_id:int}>}
	 */
	public static function assignment( string $key ): array {
		$all = self::assignments();
		$row = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : [];
		$locales = isset( $row['locales'] ) && is_array( $row['locales'] ) ? $row['locales'] : [];
		$default = isset( $row['default'] ) && is_array( $row['default'] ) ? $row['default'] : null;
		return [
			'default' => $default,
			'locales' => $locales,
		];
	}

	/**
	 * Persist one role assignment through the canonical validation boundary.
	 *
	 * @param array{type:string,object_id:int}|null          $default
	 * @param array<string,array{type:string,object_id:int}> $locales
	 */
	public static function set_assignment( string $key, ?array $default, array $locales ): bool {
		if ( null === ResourceRegistry::get( $key ) ) {
			return false;
		}
		if ( null !== $default && ! Resolver::is_valid_reference( $default ) ) {
			return false;
		}

		$normalized_locales = [];
		foreach ( $locales as $locale => $reference ) {
			if ( ! is_string( $locale ) || ! is_array( $reference ) ) {
				return false;
			}
			$normalized_locale = Resolver::normalize_locale( $locale );
			if ( '' === $normalized_locale || ! Resolver::is_valid_reference( $reference ) ) {
				return false;
			}
			$normalized_locales[ $normalized_locale ] = $reference;
		}

		$all = self::assignments();
		if ( null === $default && [] === $normalized_locales ) {
			unset( $all[ $key ] );
		} else {
			ksort( $normalized_locales );
			$all[ $key ] = [
				'default' => $default,
				'locales' => $normalized_locales,
			];
		}
		update_option( self::OPTION_ASSIGNMENTS, $all, false );
		return true;
	}
}
