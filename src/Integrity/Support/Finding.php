<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use function is_array;
use function ltrim;
use function preg_replace;
use function rtrim;
use function sanitize_key;
use function str_replace;
use function trim;

/**
 * Canonical Core Scanner Finding Schema 1 normalizer.
 *
 * A finding has one representation only: type + target + meta, with status,
 * severity and optional evidence/lifecycle metadata. Scanner producers use this
 * factory too so persisted and operator-facing state share one representation.
 */
final class Finding {
	public const SCHEMA_VERSION = 1;

	public static function make( array $args ): array {
		$type       = sanitize_key( (string) ( $args['type'] ?? 'other' ) );
		$status     = sanitize_key( (string) ( $args['status'] ?? 'notice' ) );
		$severity   = isset( $args['severity'] ) ? SeverityMapper::normalize( (string) $args['severity'] ) : SeverityMapper::from_status( $status );
		$category   = self::normalise_category( (string) ( $args['category'] ?? 'tampering' ) );
		$message    = (string) ( $args['message'] ?? '' );
		$meta       = is_array( $args['meta'] ?? null ) ? $args['meta'] : [];
		$target_arg = is_array( $args['target'] ?? null ) ? $args['target'] : [];
		$slug       = sanitize_key( (string) ( $target_arg['slug'] ?? '' ) );
		$label      = (string) ( $target_arg['label'] ?? $slug );
		$root       = self::normalise_relative_path( (string) ( $target_arg['path'] ?? self::default_root( $type, $slug ) ) );
		$file       = self::normalise_relative_path( (string) ( $target_arg['file'] ?? '' ) );
		$full_path  = self::join_path( $root, $file );

		$target = [
			'slug'  => $slug,
			'label' => $label,
			'path'  => $root,
			'file'  => $file,
		];

		$id = (string) ( $args['id'] ?? '' );
		if ( '' === $id ) {
			$id = self::id_for( $type, $slug, $full_path, sanitize_key( (string) ( $meta['identity'] ?? '' ) ) );
		}

		$finding = [
			'id'             => $id,
			'finding_schema' => self::SCHEMA_VERSION,
			'type'           => $type,
			'category'       => $category,
			'target'         => $target,
			'status'         => $status,
			'severity'       => $severity,
			'message'        => $message,
			'meta'           => $meta,
			'verification'   => self::verification( is_array( $args['verification'] ?? null ) ? $args['verification'] : [], $status, $type ),
			'children'       => self::children( is_array( $args['children'] ?? null ) ? $args['children'] : [], $type ),
		];

		if ( is_array( $args['lifecycle'] ?? null ) ) {
			$finding['lifecycle'] = $args['lifecycle'];
		}

		return $finding;
	}

	private static function children( array $children, string $type ): array {
		$out = [];
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$target_arg = is_array( $child['target'] ?? null ) ? $child['target'] : [];
			$status     = sanitize_key( (string) ( $child['status'] ?? 'ok' ) );
			$target     = [
				'slug'  => sanitize_key( (string) ( $target_arg['slug'] ?? '' ) ),
				'label' => (string) ( $target_arg['label'] ?? '' ),
				'path'  => self::normalise_relative_path( (string) ( $target_arg['path'] ?? '' ) ),
				'file'  => self::normalise_relative_path( (string) ( $target_arg['file'] ?? '' ) ),
			];

			$out[] = [
				'status'       => $status,
				'severity'     => isset( $child['severity'] ) ? SeverityMapper::normalize( (string) $child['severity'] ) : SeverityMapper::from_status( $status ),
				'target'       => $target,
				'message'      => (string) ( $child['message'] ?? '' ),
				'verification' => self::verification( is_array( $child['verification'] ?? null ) ? $child['verification'] : [], $status, $type ),
				'meta'         => is_array( $child['meta'] ?? null ) ? $child['meta'] : [],
			];
		}

		return $out;
	}

	private static function normalise_category( string $category ): string {
		$category = sanitize_key( $category );

		if ( 'distribution_drift' === $category || 'informational' === $category ) {
			return $category;
		}

		return 'tampering';
	}

	private static function id_for( string $type, string $slug, string $path, string $identity = '' ): string {
		$path   = str_replace( [ '/', '.', '_' ], '-', $path );
		$suffix = '' !== $identity ? '-' . $identity : '';
		return sanitize_key( $type . '-' . $slug . '-' . $path . $suffix );
	}

	private static function verification( array $verification, string $status, string $type ): array {
		if ( [] !== $verification ) {
			$scope = sanitize_key( (string) ( $verification['scope'] ?? '' ) );
			$out = [
				'method'     => (string) ( $verification['method'] ?? 'unknown' ),
				'source'     => (string) ( $verification['source'] ?? 'unknown' ),
				'confidence' => (string) ( $verification['confidence'] ?? 'low' ),
				'label'      => (string) ( $verification['label'] ?? '' ),
			];
			if ( in_array( $scope, [ 'component', 'file' ], true ) ) {
				$out['scope'] = $scope;
			}
			return $out;
		}

		if ( 'baseline_required' === $status || 'verification_failed' === $status ) {
			return [
				'method'     => 'local_baseline',
				'source'     => 'approved_local_baseline',
				'confidence' => 'medium',
				'label'      => __( 'Local approved baseline', 'core-blueprint' ),
			];
		}

		if ( 'plugin' === $type || 'theme' === $type || 'core' === $type ) {
			return [
				'method'     => 'checksum',
				'source'     => 'wordpress.org',
				'confidence' => 'high',
				'label'      => __( 'Checksum via WordPress.org', 'core-blueprint' ),
			];
		}

		return [
			'method'     => 'filesystem',
			'source'     => 'local_filesystem',
			'confidence' => 'low',
			'label'      => __( 'Local filesystem scan', 'core-blueprint' ),
		];
	}

	private static function default_root( string $type, string $slug ): string {
		if ( 'core' === $type ) {
			return './';
		}

		if ( 'plugin' === $type && '' !== $slug ) {
			return 'wp-content/plugins/' . $slug . '/';
		}

		if ( 'theme' === $type && '' !== $slug ) {
			return 'wp-content/themes/' . $slug . '/';
		}

		if ( 'uploads' === $type ) {
			return 'wp-content/uploads/';
		}

		return '';
	}

	private static function join_path( string $root, string $file ): string {
		if ( '' === $file ) {
			return $root;
		}

		if ( '' === $root || './' === $root ) {
			return $file;
		}

		return rtrim( $root, '/' ) . '/' . ltrim( $file, '/' );
	}

	private static function normalise_relative_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = preg_replace( '#/+#', '/', $path );

		if ( null === $path ) {
			return '';
		}

		return ltrim( $path, '/' );
	}
}
