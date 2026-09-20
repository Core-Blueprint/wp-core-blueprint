<?php
declare(strict_types=1);

namespace CB\Core\Profiles;

defined( 'ABSPATH' ) || exit;

final class Document {
	public const FORMAT = 'core-blueprint-profile';
	public const FORMAT_VERSION = 1;
	public const MAX_BYTES = 1048576;
	public const MAX_SECTIONS = 32;
	public const MAX_CHANGES = 1000;

	public static function decode( string $json ): array {
		if ( '' === trim( $json ) ) {
			throw new \InvalidArgumentException( __( 'The profile file is empty.', 'core-blueprint' ) );
		}
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( __( 'The profile file is too large.', 'core-blueprint' ) );
		}
		try {
			$data = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw new \InvalidArgumentException( __( 'The profile file is not valid JSON.', 'core-blueprint' ), 0, $error );
		}
		if ( ! is_array( $data ) || ! isset( $data['format'] ) || ! is_string( $data['format'] ) || self::FORMAT !== $data['format'] ) {
			throw new \InvalidArgumentException( __( 'This is not a Core Blueprint Profile.', 'core-blueprint' ) );
		}
		SchemaGuard::exact_keys( $data, [ 'format', 'format_version', 'profile', 'source', 'exported_at', 'sections' ], 'Profile document' );
		if ( ! isset( $data['format_version'] ) || ! is_int( $data['format_version'] ) || self::FORMAT_VERSION !== $data['format_version'] ) {
			throw new \InvalidArgumentException( __( 'This Core Blueprint Profile format version is not supported.', 'core-blueprint' ) );
		}
		$profile = is_array( $data['profile'] ?? null ) ? $data['profile'] : [];
		SchemaGuard::exact_keys( $profile, [ 'name', 'description' ], 'Profile metadata' );
		if ( ! isset( $profile['name'], $profile['description'] ) || ! is_string( $profile['name'] ) || ! is_string( $profile['description'] ) ) {
			throw new \InvalidArgumentException( __( 'The profile metadata is invalid.', 'core-blueprint' ) );
		}
		$name = sanitize_text_field( $profile['name'] );
		$description = sanitize_textarea_field( $profile['description'] );
		if ( '' === $name || strlen( $name ) > 80 || strlen( $description ) > 500 ) {
			throw new \InvalidArgumentException( __( 'The profile metadata is invalid.', 'core-blueprint' ) );
		}
		$source = is_array( $data['source'] ?? null ) && ! array_is_list( $data['source'] ) ? $data['source'] : [];
		SchemaGuard::exact_keys( $source, [ 'base_version' ], 'Profile source' );
		if ( ! isset( $source['base_version'] ) || ! is_string( $source['base_version'] ) || ! isset( $data['exported_at'] ) || ! is_string( $data['exported_at'] ) ) {
			throw new \InvalidArgumentException( __( 'The profile metadata is invalid.', 'core-blueprint' ) );
		}
		$base_version = sanitize_text_field( $source['base_version'] );
		$exported_at = sanitize_text_field( $data['exported_at'] );
		if ( '' === $base_version || strlen( $base_version ) > 64 || $base_version !== $source['base_version'] || '' === $exported_at || strlen( $exported_at ) > 64 || $exported_at !== $data['exported_at'] ) {
			throw new \InvalidArgumentException( __( 'The profile metadata is invalid.', 'core-blueprint' ) );
		}
		$sections = is_array( $data['sections'] ?? null ) ? $data['sections'] : [];
		if ( [] === $sections || count( $sections ) > self::MAX_SECTIONS ) {
			throw new \InvalidArgumentException( __( 'The profile section list is invalid.', 'core-blueprint' ) );
		}
		$normalized_sections = [];
		foreach ( $sections as $id => $section ) {
			$id = (string) $id;
			if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id ) || ! is_array( $section ) ) {
				throw new \InvalidArgumentException( __( 'The profile contains an invalid section.', 'core-blueprint' ) );
			}
			SchemaGuard::exact_keys( $section, [ 'schema_version', 'data' ], 'Profile section' );
			$schema_version = $section['schema_version'] ?? null;
			$payload = $section['data'] ?? null;
			if ( ! is_int( $schema_version ) || $schema_version < 1 || ! is_array( $payload ) || array_is_list( $payload ) ) {
				throw new \InvalidArgumentException( __( 'The profile contains an invalid section payload.', 'core-blueprint' ) );
			}
			$normalized_sections[ $id ] = [
				'schema_version' => $schema_version,
				'data'           => $payload,
			];
		}

		return [
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'profile'        => [ 'name' => $name, 'description' => $description ],
			'source'         => [
				'base_version' => $base_version,
			],
			'exported_at'    => $exported_at,
			'sections'       => $normalized_sections,
		];
	}

	public static function build( string $name, string $description, array $sections ): array {
		$name = sanitize_text_field( $name );
		$description = sanitize_textarea_field( $description );
		if ( '' === $name || strlen( $name ) > 80 || strlen( $description ) > 500 ) {
			throw new \InvalidArgumentException( __( 'Enter a valid profile name and description.', 'core-blueprint' ) );
		}
		if ( [] === $sections || count( $sections ) > self::MAX_SECTIONS ) {
			throw new \InvalidArgumentException( __( 'Select at least one profile section.', 'core-blueprint' ) );
		}
		return [
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'profile'        => [ 'name' => $name, 'description' => $description ],
			'source'         => [ 'base_version' => defined( 'CB_CORE_VERSION' ) ? (string) CB_CORE_VERSION : '' ],
			'exported_at'    => gmdate( 'c' ),
			'sections'       => $sections,
		];
	}
}
