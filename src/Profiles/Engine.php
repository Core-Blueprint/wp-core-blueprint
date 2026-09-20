<?php
declare(strict_types=1);

namespace CB\Core\Profiles;

use CB\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Engine {
	public static function export_document( string $name, string $description, array $selected_ids ): array {
		$selected = [];
		foreach ( $selected_ids as $raw_id ) {
			if ( ! is_string( $raw_id ) || $raw_id !== sanitize_key( $raw_id ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $raw_id ) ) {
				throw new \InvalidArgumentException( __( 'The profile export contains an unknown section.', 'core-blueprint' ) );
			}
			if ( ! in_array( $raw_id, $selected, true ) ) {
				$selected[] = $raw_id;
			}
		}
		$registry = SectionRegistry::all();
		$sections = [];
		foreach ( $selected as $id ) {
			if ( ! isset( $registry[ $id ] ) ) {
				throw new \InvalidArgumentException( __( 'The profile export contains an unknown section.', 'core-blueprint' ) );
			}
			$section = $registry[ $id ];
			$sections[ $id ] = [
				'schema_version' => $section->schema_version(),
				'data'           => $section->normalize( $section->export() ),
			];
		}
		return Document::build( $name, $description, $sections );
	}

	public static function preview( array $document ): array {
		$document = self::normalize_document( $document );
		$registry = SectionRegistry::all();
		$sections = [];
		$current = [];
		$total_changes = 0;
		foreach ( $document['sections'] as $id => $payload ) {
			$section = $registry[ $id ];
			$incoming = $payload['data'];
			$current[ $id ] = $section->snapshot();
			$section->preflight( $incoming, $current[ $id ] );
			$changes = $section->preview( $current[ $id ], $incoming );
			$total_changes += count( $changes );
			if ( $total_changes > Document::MAX_CHANGES ) {
				throw new \RuntimeException( sprintf(
					/* translators: %d: maximum number of changes that can be reviewed */
					__( 'This profile produces more than %d configuration changes and cannot be reviewed safely in one operation.', 'core-blueprint' ),
					Document::MAX_CHANGES
				) );
			}
			$sections[ $id ] = [
				'label'       => $section->label(),
				'description' => $section->description(),
				'warnings'    => $section->warnings( $incoming ),
				'changes'     => $changes,
				'count'       => count( $changes ),
			];
		}
		$fingerprint = hash( 'sha256', CanonicalJson::encode( [
			'document' => $document,
			'current'  => $current,
		] ) );
		return [
			'fingerprint' => $fingerprint,
			'total_changes' => $total_changes,
			'sections' => $sections,
			'snapshots' => $current,
			'document' => $document,
		];
	}

	public static function apply( array $document, string $expected_fingerprint, string $actor ): array {
		$preview = self::preview( $document );
		$document = $preview['document'];
		if ( '' === $expected_fingerprint || ! hash_equals( $preview['fingerprint'], $expected_fingerprint ) ) {
			throw new \RuntimeException( __( 'Configuration changed since this preview was created. Review the profile again before applying it.', 'core-blueprint' ) );
		}

		$lock = ApplyLock::acquire( $actor );
		$applied = [];
		$attempted = [];
		$snapshots = [];
		$registry = SectionRegistry::all();
		try {
			// Re-check after acquiring the exclusive mutation lease.
			$locked_preview = self::preview( $document );
			if ( ! hash_equals( $expected_fingerprint, $locked_preview['fingerprint'] ) ) {
				throw new \RuntimeException( __( 'Configuration changed while the profile was waiting to apply. Review it again.', 'core-blueprint' ) );
			}

			$snapshots = is_array( $locked_preview['snapshots'] ?? null ) ? $locked_preview['snapshots'] : [];

			foreach ( $document['sections'] as $id => $payload ) {
				$section = $registry[ $id ];
				if ( ! ApplyLock::refresh( $lock ) ) {
					throw new \RuntimeException( __( 'The profile apply lock was lost before the next section could be changed.', 'core-blueprint' ) );
				}
				if ( ! self::same_state( $section->snapshot(), $snapshots[ $id ] ) ) {
					throw new \RuntimeException( __( 'Configuration changed while the profile was being applied. The transaction was stopped before touching that section.', 'core-blueprint' ) );
				}
				$attempted[] = $id;
				$section->apply( $payload['data'], $actor );
				if ( ! $section->verify( $payload['data'] ) ) {
					throw new \RuntimeException( sprintf( __( 'Profile section %s did not verify after apply.', 'core-blueprint' ), $id ) );
				}
				$applied[] = $id;
			}

			AuditLog::log( 'profiles.applied', 'notice', [
				'actor'          => $actor,
				'profile_name'   => (string) $document['profile']['name'],
				'sections'       => $applied,
				'change_count'   => (int) $preview['total_changes'],
				'fingerprint'    => $expected_fingerprint,
			] );
			return [ 'status' => 'complete', 'sections' => $applied, 'change_count' => (int) $preview['total_changes'] ];
		} catch ( \Throwable $error ) {
			$rollback_failures = [];
			foreach ( array_reverse( $attempted ) as $id ) {
				try {
					$registry[ $id ]->restore( $snapshots[ $id ], (array) ( $document['sections'][ $id ]['data'] ?? [] ), 'profile-rollback:' . $actor );
					if ( ! self::same_state( $registry[ $id ]->snapshot(), $snapshots[ $id ] ) ) {
						throw new \RuntimeException( 'restore verification failed' );
					}
				} catch ( \Throwable $rollback_error ) {
					$rollback_failures[ $id ] = substr( sanitize_text_field( $rollback_error->getMessage() ), 0, 500 );
				}
			}
			AuditLog::log( empty( $rollback_failures ) ? 'profiles.apply_failed' : 'profiles.rollback_failed', empty( $rollback_failures ) ? 'warning' : 'critical', [
				'actor'             => $actor,
				'profile_name'      => (string) ( $document['profile']['name'] ?? '' ),
				'applied_sections'  => $applied,
				'attempted_sections'=> $attempted,
				'rollback_failures' => $rollback_failures,
				'error'             => substr( sanitize_text_field( $error->getMessage() ), 0, 500 ),
			] );
			if ( ! empty( $rollback_failures ) ) {
				throw new \RuntimeException( __( 'The profile could not be applied and one or more sections could not be restored automatically. Review the Audit Log before making further configuration changes.', 'core-blueprint' ), 0, $error );
			}
			throw $error;
		} finally {
			ApplyLock::release( $lock );
		}
	}


	private static function same_state( array $left, array $right ): bool {
		return hash_equals(
			hash( 'sha256', CanonicalJson::encode( $left ) ),
			hash( 'sha256', CanonicalJson::encode( $right ) )
		);
	}

	private static function normalize_document( array $document ): array {
		// Programmatic consumers are held to the exact same bounded transport
		// contract as uploaded JSON documents.
		$document = Document::decode( CanonicalJson::encode( $document ) );
		if ( Document::FORMAT !== (string) ( $document['format'] ?? '' ) || Document::FORMAT_VERSION !== (int) ( $document['format_version'] ?? 0 ) ) {
			throw new \InvalidArgumentException( __( 'This is not a supported Core Blueprint Profile.', 'core-blueprint' ) );
		}
		$registry = SectionRegistry::all();
		$sections = is_array( $document['sections'] ?? null ) ? $document['sections'] : [];
		if ( [] === $sections ) {
			throw new \InvalidArgumentException( __( 'The profile contains no sections.', 'core-blueprint' ) );
		}
		foreach ( $sections as $id => &$payload ) {
			if ( ! isset( $registry[ $id ] ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Profile section %s is not available on this site.', 'core-blueprint' ), (string) $id ) );
			}
			$section = $registry[ $id ];
			$source_schema_version = (int) ( $payload['schema_version'] ?? 0 );
			if ( ! $section->supports_schema_version( $source_schema_version ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Profile section %s uses an unsupported schema version.', 'core-blueprint' ), (string) $id ) );
			}
			$data = $payload['data'] ?? null;
			if ( ! is_array( $data ) ) {
				throw new \InvalidArgumentException( __( 'The profile contains an invalid section payload.', 'core-blueprint' ) );
			}
			$payload['data'] = $section->migrate( $data, $source_schema_version );
			$payload['schema_version'] = $section->schema_version();
		}
		unset( $payload );
		$ordered = [];
		foreach ( array_keys( $registry ) as $id ) {
			if ( isset( $sections[ $id ] ) ) {
				$ordered[ $id ] = $sections[ $id ];
			}
		}
		$document['sections'] = $ordered;
		return $document;
	}
}
