<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Profiles;

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Profiles\Sections\ReportsSection;

defined( 'ABSPATH' ) || exit;

final class Engine {
	public static function export_document( string $name, string $description, array $selected_ids ): array {
		$selected = [];
		foreach ( $selected_ids as $raw_id ) {
			if ( ! is_string( $raw_id ) || $raw_id !== sanitize_key( $raw_id ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $raw_id ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'The profile export contains an unknown section.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			if ( ! in_array( $raw_id, $selected, true ) ) {
				$selected[] = $raw_id;
			}
		}
		$registry = SectionRegistry::all();
		$sections = [];
		foreach ( $selected as $id ) {
			if ( ! isset( $registry[ $id ] ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'The profile export contains an unknown section.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
		$witnesses = [];
		$total_changes = 0;
		foreach ( $document['sections'] as $id => $payload ) {
			$section = $registry[ $id ];
			$incoming = $payload['data'];
			$current[ $id ] = $section->snapshot();
			$section->preflight( $incoming, $current[ $id ] );
			if ( $section instanceof VersionGuardedSectionInterface ) {
				$witnesses[ $id ] = $section->version_witness();
			}
			$changes = $section->preview( $current[ $id ], $incoming );
			$total_changes += count( $changes );
			if ( $total_changes > Document::MAX_CHANGES ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \RuntimeException( sprintf(
					/* translators: %d: maximum number of changes that can be reviewed */
					__( 'This profile produces more than %d configuration changes and cannot be reviewed safely in one operation.', 'core-blueprint' ),
					Document::MAX_CHANGES
				) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
			'document'  => $document,
			'current'   => $current,
			'witnesses' => $witnesses,
		] ) );
		return [
			'fingerprint' => $fingerprint,
			'total_changes' => $total_changes,
			'sections' => $sections,
			'snapshots' => $current,
			'witnesses' => $witnesses,
			'document' => $document,
		];
	}

	public static function apply( array $document, string $expected_fingerprint, string $actor ): array {
		$preview = self::preview( $document );
		$document = $preview['document'];
		// A Reports-only zero-change import has nothing to authorize; do not
		// acquire a mutation lease merely to manufacture an unchanged write.
		// A mixed Profile with a no-op Reports section still uses the strict
		// guarded no-write receipt while applying its other changed sections.
		if ( 0 === (int) $preview['total_changes']
			&& isset( $document['sections']['reports'] )
			&& 'active' === ( $preview['witnesses']['reports']['authority'] ?? null ) ) {
			throw new \RuntimeException( __( 'The active Reports profile contains no changes to apply.', 'core-blueprint' ) );
		}
		// A read-only canonical Reports preview is not permission to apply.
		// Check every guarded section before even acquiring the apply lock.
		foreach ( $document['sections'] as $id => $payload ) {
			$section = SectionRegistry::all()[ $id ];
			if ( $section instanceof VersionGuardedSectionInterface ) {
				$section->assert_apply_allowed();
			}
		}
		if ( '' === $expected_fingerprint || ! hash_equals( $preview['fingerprint'], $expected_fingerprint ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'Configuration changed since this preview was created. Review the profile again before applying it.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$lock = ApplyLock::acquire( $actor );
		$applied = [];
		$attempted = [];
		$post_witnesses = [];
		$unchanged_sections = [];
		$before_witnesses = [];
		$snapshots = [];
		$registry = SectionRegistry::all();
		try {
			// Re-check after acquiring the exclusive mutation lease.
			$locked_preview = self::preview( $document );
			if ( ! hash_equals( $expected_fingerprint, $locked_preview['fingerprint'] ) ) {
				throw new \RuntimeException( __( 'Configuration changed while the profile was waiting to apply. Review it again.', 'core-blueprint' ) );
			}

			$snapshots = is_array( $locked_preview['snapshots'] ?? null ) ? $locked_preview['snapshots'] : [];
			$before_witnesses = is_array( $locked_preview['witnesses'] ?? null ) ? $locked_preview['witnesses'] : [];
			foreach ( $document['sections'] as $id => $payload ) {
				$section = $registry[ $id ];
				if ( $section instanceof VersionGuardedSectionInterface ) {
					$section->assert_apply_allowed();
				}
			}

			foreach ( $document['sections'] as $id => $payload ) {
				$section = $registry[ $id ];
				if ( ! ApplyLock::refresh( $lock ) ) {
					throw new \RuntimeException( __( 'The profile apply lock was lost before the next section could be changed.', 'core-blueprint' ) );
				}
				if ( ! self::same_state( $section->snapshot(), $snapshots[ $id ] ) ) {
					throw new \RuntimeException( __( 'Configuration changed while the profile was being applied. The transaction was stopped before touching that section.', 'core-blueprint' ) );
				}
				if ( $section instanceof VersionGuardedSectionInterface
					&& ( $section->version_witness() !== ( $before_witnesses[ $id ] ?? null ) ) ) {
					throw new \RuntimeException( 'Profile revision conflict' );
				}
				$attempted[] = $id;
				$strict_reports = $section instanceof ReportsSection
					&& 'active' === ( $before_witnesses[ $id ]['authority'] ?? null );
				if ( $strict_reports ) {
					// The exactly approved in-memory, post-lease preimage, never
					// a fresh witness taken after a different writer's changes.
					$result = $section->apply_profile_guarded(
						$payload['data'], $actor, $before_witnesses[ $id ]
					);
					$status = $result['status'] ?? '';
					if ( ! in_array( $status, [ 'saved', 'unchanged' ], true )
						|| ! is_array( $result['witness'] ?? null )
						|| count( $result['witness'] ) !== 3 ) {
						throw new \RuntimeException( 'Reports Profile recovery_required: guarded_transaction_' . (
							in_array( $status, [ 'conflict', 'refused', 'invalid', 'blocked', 'failed', 'recovery_required' ], true )
								? $status : 'unavailable'
						) );
					}
					if ( 'saved' === $status ) {
						// Preserve original S2 postcommit receipt BEFORE verify,
						// next section or observer; a later read cannot replace it.
						$post_witnesses[ $id ] = $result['witness'];
					} else {
						$unchanged_sections[ $id ] = true;
					}
				} else {
					$section->apply( $payload['data'], $actor );
				}
				if ( ! $section->verify( $payload['data'] ) ) {
					/* translators: %s: profile section ID. */
					throw new \RuntimeException( sprintf( __( 'Profile section %s did not verify after apply.', 'core-blueprint' ), $id ) );
				}
				$applied[] = $id;
				if ( $section instanceof VersionGuardedSectionInterface && ! $strict_reports ) {
					$post_witnesses[ $id ] = $section->version_witness();
				}
			}

			AuditLog::log( 'profiles.applied', 'notice', [
				'actor'          => $actor,
				'sections'       => $applied,
				'change_count'   => (int) $preview['total_changes'],
				'fingerprint'    => $expected_fingerprint,
			] );
			return [ 'status' => 'complete', 'sections' => $applied, 'change_count' => (int) $preview['total_changes'] ];
		} catch ( \Throwable $error ) {
			$rollback_failures = [];
			foreach ( array_reverse( $attempted ) as $id ) {
				try {
					$section = $registry[ $id ];
					if ( isset( $unchanged_sections[ $id ] ) ) {
						// A verified no-op is never reverse-written; if another
						// actor changed state, report recovery rather than clobber.
						if ( ! $section instanceof VersionGuardedSectionInterface
							|| $section->version_witness() !== ( $before_witnesses[ $id ] ?? null ) ) {
							throw new \RuntimeException( 'Reports Profile recovery_required: no_write_changed' );
						}
					} elseif ( $section instanceof VersionGuardedSectionInterface ) {
						$section->restore_guarded(
							$snapshots[ $id ], (array) ( $document['sections'][ $id ]['data'] ?? [] ),
							'profile-rollback:' . $actor,
							$before_witnesses[ $id ] ?? [], $post_witnesses[ $id ] ?? null
						);
					} else {
						$section->restore( $snapshots[ $id ], (array) ( $document['sections'][ $id ]['data'] ?? [] ), 'profile-rollback:' . $actor );
					}
					if ( ! self::same_state( $registry[ $id ]->snapshot(), $snapshots[ $id ] ) ) {
						throw new \RuntimeException( 'restore verification failed' );
					}
				} catch ( \Throwable $rollback_error ) {
					$rollback_failures[ $id ] = 'recovery_required';
				}
			}
			AuditLog::log( empty( $rollback_failures ) ? 'profiles.apply_failed' : 'profiles.rollback_failed', empty( $rollback_failures ) ? 'warning' : 'critical', [
				'actor'             => $actor,
				'applied_sections'  => $applied,
				'attempted_sections'=> $attempted,
				'rollback_failures' => $rollback_failures,
				'reason_code'       => empty( $rollback_failures ) ? 'section_apply_failed' : 'recovery_required',
			] );
			if ( ! empty( $rollback_failures ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \RuntimeException( __( 'The profile could not be applied and one or more sections could not be restored automatically. Review the Audit Log before making further configuration changes.', 'core-blueprint' ), 0, $error );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'This is not a supported Core Blueprint Profile.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$registry = SectionRegistry::all();
		$sections = is_array( $document['sections'] ?? null ) ? $document['sections'] : [];
		if ( [] === $sections ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The profile contains no sections.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		foreach ( $sections as $id => &$payload ) {
			if ( ! isset( $registry[ $id ] ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				/* translators: %s: profile section ID. */
				throw new \InvalidArgumentException( sprintf( __( 'Profile section %s is not available on this site.', 'core-blueprint' ), (string) $id ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$section = $registry[ $id ];
			$source_schema_version = (int) ( $payload['schema_version'] ?? 0 );
			if ( ! $section->supports_schema_version( $source_schema_version ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				/* translators: %s: profile section ID. */
				throw new \InvalidArgumentException( sprintf( __( 'Profile section %s uses an unsupported schema version.', 'core-blueprint' ), (string) $id ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$data = $payload['data'] ?? null;
			if ( ! is_array( $data ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'The profile contains an invalid section payload.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
