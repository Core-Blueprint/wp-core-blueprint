<?php
declare(strict_types=1);
/**
 * Build and atomically apply vendor-neutral WordPress-native adoption plans.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\ContentModels\Importers\NativeWordPress;

use CoreBlueprint\Core\ContentModels\Repository;
use CoreBlueprint\Core\ContentModels\SchemaTransfer;
use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Importer {
	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function create_plan( array $input ): array {
		$preview = PlanStore::discovery();
		if ( ! is_array( $preview ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The WordPress discovery preview expired. Discover the runtime schema again.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$fresh = Discovery::snapshot();
		$preview_by_token = self::by_token( $preview );
		$fresh_by_token   = self::by_token( $fresh );
		$selected = array_values( array_unique( array_filter( array_map( [ __CLASS__, 'clean_token' ], (array) ( $input['selected'] ?? [] ) ) ) ) );
		if ( [] === $selected ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'Select at least one native WordPress definition to include in the import plan.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$labels = is_array( $input['meta_label'] ?? null ) ? $input['meta_label'] : [];
		$field_types = is_array( $input['meta_field_type'] ?? null ) ? $input['meta_field_type'] : [];
		$group_titles = is_array( $input['group_title'] ?? null ) ? $input['group_title'] : [];
		$plan_id = wp_generate_uuid4();
		$document = [
			'schema_version' => Repository::SCHEMA_VERSION,
			'post_types'     => [],
			'taxonomies'     => [],
			'option_pages'    => [],
			'field_groups'   => [],
		];
		$targets = [];
		$selected_post_types = [];
		$selected_taxonomies = [];
		$meta_groups = [];

		foreach ( $selected as $token ) {
			$before = $preview_by_token[ $token ] ?? null;
			$current = $fresh_by_token[ $token ] ?? null;
			if ( ! is_array( $before ) || ! is_array( $current ) || ! isset( $before['fingerprint'], $current['fingerprint'] ) || ! hash_equals( (string) $before['fingerprint'], (string) $current['fingerprint'] ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'The WordPress runtime schema changed after discovery. Discover it again before creating an import plan.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$kind = (string) ( $current['kind'] ?? '' );
			if ( in_array( $kind, [ 'post_type', 'taxonomy' ], true ) ) {
				if ( Discovery::READY !== (string) ( $current['status'] ?? '' ) || ! is_array( $current['definition'] ?? null ) ) {
					// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
					throw new \InvalidArgumentException( __( 'Only definitions marked Ready can be added to the import plan.', 'core-blueprint' ) );
					// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
				$key = (string) ( $current['key'] ?? '' );
				if ( 'post_type' === $kind ) {
					$document['post_types'][ $key ] = $current['definition'];
					$selected_post_types[ $key ] = true;
				} else {
					$document['taxonomies'][ $key ] = $current['definition'];
					$selected_taxonomies[ $key ] = true;
				}
				$targets[] = [ 'kind' => $kind, 'key' => $key, 'fingerprint' => (string) $current['fingerprint'] ];
				continue;
			}
			if ( 'meta' !== $kind || Discovery::MAPPING_REQUIRED !== (string) ( $current['status'] ?? '' ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'The selected metadata entry is not eligible for explicit mapping.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			$label = sanitize_text_field( (string) ( $labels[ $token ] ?? '' ) );
			$field_type = sanitize_key( (string) ( $field_types[ $token ] ?? '' ) );
			$allowed = is_array( $current['allowed_field_types'] ?? null ) ? $current['allowed_field_types'] : [];
			if ( '' === $label || ! in_array( $field_type, $allowed, true ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'Every selected metadata key requires an explicit label and compatible Content Models field type.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$context_token = (string) ( $current['context_token'] ?? '' );
			$group_title = sanitize_text_field( (string) ( $group_titles[ $context_token ] ?? '' ) );
			if ( '' === $group_title ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'Every selected metadata context requires an explicit Field Group title.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$compatibility = ValueCompatibility::inspect( $current, $field_type );
			if ( empty( $compatibility['compatible'] ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( (string) ( $compatibility['reason'] ?? __( 'Existing metadata values are not compatible with the selected field mapping.', 'core-blueprint' ) ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			$context_id = (string) ( $current['context_id'] ?? '' );
			if ( ! isset( $meta_groups[ $context_id ] ) ) {
				$group_id = 'group_wp_' . substr( hash( 'sha256', $plan_id . '|' . $context_id ), 0, 18 );
				$meta_groups[ $context_id ] = [
					'group_id' => $group_id,
					'title'    => $group_title,
					'entry'    => $current,
					'fields'   => [],
				];
			} elseif ( $group_title !== (string) $meta_groups[ $context_id ]['title'] ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'Use one Field Group title for all selected metadata in the same WordPress context.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$field_id = 'field_wp_' . substr( hash( 'sha256', $plan_id . '|' . (string) $current['id'] ), 0, 18 );
			$field = Repository::normalize_field( [
				'id'            => $field_id,
				'name'          => (string) ( $current['key'] ?? '' ),
				'label'         => $label,
				'type'          => $field_type,
				'instructions'  => '',
				'required'      => false,
				'show_in_rest'  => ! empty( $current['show_in_rest'] ),
				'placeholder'   => '',
				'default_value' => $current['default'] ?? '',
			] );
			$meta_groups[ $context_id ]['fields'][ $field_id ] = $field;
			$targets[] = [
				'kind'             => 'meta',
				'key'              => (string) ( $current['key'] ?? '' ),
				'object_type'      => (string) ( $current['object_type'] ?? '' ),
				'object_subtype'   => (string) ( $current['object_subtype'] ?? '' ),
				'field_type'       => $field_type,
				'fingerprint'      => (string) $current['fingerprint'],
				'value_digest'     => (string) $compatibility['digest'],
				'value_count'      => (int) $compatibility['count'],
				'default'          => $current['default'] ?? '',
			];
		}

		foreach ( $document['taxonomies'] as $definition ) {
			foreach ( (array) ( $definition['object_types'] ?? [] ) as $post_type ) {
				self::assert_context_survives_handover( 'post', (string) $post_type, $selected_post_types, $selected_taxonomies );
			}
		}
		foreach ( $meta_groups as $context_id => $group_data ) {
			$entry = $group_data['entry'];
			$object_type = (string) ( $entry['object_type'] ?? '' );
			$subtype = (string) ( $entry['object_subtype'] ?? '' );
			if ( in_array( $object_type, [ 'post', 'term' ], true ) ) {
				self::assert_context_survives_handover( $object_type, $subtype, $selected_post_types, $selected_taxonomies );
			}
			$group_input = [
				'id'                => (string) $group_data['group_id'],
				'title'             => (string) $group_data['title'],
				'description'       => '',
				'post_types'        => 'post' === $object_type ? [ $subtype ] : [],
				'option_pages'      => [],
				'term_taxonomies'   => 'term' === $object_type ? [ $subtype ] : [],
				'user_enabled'      => 'user' === $object_type,
				'user_roles'        => [],
				'context'           => 'normal',
				'priority'          => 'default',
			];
			$group = Repository::normalize_field_group( $group_input );
			$group['fields'] = $group_data['fields'];
			$document['field_groups'][ (string) $group['id'] ] = $group;
		}

		$plan = PlanStore::save_plan( [
			'plan_id'  => $plan_id,
			'document' => $document,
			'targets'  => $targets,
			'summary'  => [
				'post_types'   => count( $document['post_types'] ),
				'taxonomies'   => count( $document['taxonomies'] ),
				'field_groups' => count( $document['field_groups'] ),
				'meta_fields'  => count( array_filter( $targets, static fn( array $target ): bool => 'meta' === (string) ( $target['kind'] ?? '' ) ) ),
			],
		] );
		return $plan;
	}

	/** @return array<string,int> */
	public static function apply_plan(): array {
		$plan = PlanStore::plan();
		if ( ! is_array( $plan ) || ! is_array( $plan['document'] ?? null ) || ! is_array( $plan['targets'] ?? null ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The WordPress import plan is missing, expired or failed its integrity check. Create a new plan.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( ! PlanStore::repository_unchanged( $plan ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'Content Models changed after this plan was created. Discover and review the WordPress runtime schema again.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		foreach ( $plan['targets'] as $target ) {
			if ( ! is_array( $target ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'The import plan contains an invalid target.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$kind = (string) ( $target['kind'] ?? '' );
			$key = (string) ( $target['key'] ?? '' );
			if ( 'post_type' === $kind ) {
				if ( post_type_exists( $key ) || null !== Repository::post_type( $key ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
					/* translators: %s: post type key. */
			throw new \InvalidArgumentException( sprintf( __( 'Post type “%s” is still registered or now conflicts with Content Models. Disable its original registrar and create a fresh plan.', 'core-blueprint' ), $key ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
				continue;
			}
			if ( 'taxonomy' === $kind ) {
				if ( taxonomy_exists( $key ) || null !== Repository::taxonomy( $key ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
					/* translators: %s: taxonomy key. */
			throw new \InvalidArgumentException( sprintf( __( 'Taxonomy “%s” is still registered or now conflicts with Content Models. Disable its original registrar and create a fresh plan.', 'core-blueprint' ), $key ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
				continue;
			}
			if ( 'meta' !== $kind ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'The import plan contains an unsupported target kind.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$object_type = (string) ( $target['object_type'] ?? '' );
			$subtype = (string) ( $target['object_subtype'] ?? '' );
			if ( registered_meta_key_exists( $object_type, $key, $subtype ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				/* translators: %s: registered metadata key. */
			throw new \InvalidArgumentException( sprintf( __( 'Registered metadata key “%s” is still owned by the original runtime registrar. Disable that registration before applying the plan.', 'core-blueprint' ), $key ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$compatibility = ValueCompatibility::inspect( $target, (string) ( $target['field_type'] ?? '' ) );
			if ( empty( $compatibility['compatible'] ) || (int) ( $target['value_count'] ?? -1 ) !== (int) $compatibility['count'] || ! hash_equals( (string) ( $target['value_digest'] ?? '' ), (string) $compatibility['digest'] ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'Existing metadata values changed or no longer match the reviewed field mapping. Create a fresh import plan.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}

		foreach ( (array) ( $plan['document']['field_groups'] ?? [] ) as $group_id => $group ) {
			if ( null !== Repository::field_group( (string) $group_id ) || ! is_array( $group ) || ! empty( Repository::field_group_conflicts( $group ) ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'A target Field Group or field name now conflicts with Content Models. Create a fresh import plan.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}

		$counts = SchemaTransfer::import( $plan['document'], false );
		PlanStore::clear();
		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log( 'content_models_native_imported', 'warning', [
				'counts'  => $counts,
				'targets' => array_values( array_map( static function ( array $target ): string {
					$kind = (string) ( $target['kind'] ?? '' );
					if ( 'meta' === $kind ) {
						return $kind . ':' . (string) ( $target['object_type'] ?? '' ) . ':' . (string) ( $target['object_subtype'] ?? '' ) . ':' . (string) ( $target['key'] ?? '' );
					}
					return $kind . ':' . (string) ( $target['key'] ?? '' );
				}, $plan['targets'] ) ),
			] );
		}
		return $counts;
	}

	/** @param array<string,mixed> $snapshot @return array<string,array<string,mixed>> */
	private static function by_token( array $snapshot ): array {
		$result = [];
		foreach ( [ 'post_types', 'taxonomies', 'meta' ] as $section ) {
			foreach ( (array) ( $snapshot[ $section ] ?? [] ) as $entry ) {
				if ( is_array( $entry ) && '' !== (string) ( $entry['token'] ?? '' ) ) {
					$result[ (string) $entry['token'] ] = $entry;
				}
			}
		}
		return $result;
	}

	private static function clean_token( $value ): string {
		$value = is_scalar( $value ) ? strtolower( (string) $value ) : '';
		return preg_replace( '/[^a-z0-9]/', '', $value ) ?? '';
	}

	/** @param array<string,bool> $selected_post_types @param array<string,bool> $selected_taxonomies */
	private static function assert_context_survives_handover( string $object_type, string $subtype, array $selected_post_types, array $selected_taxonomies ): void {
		if ( 'post' === $object_type ) {
			$object = get_post_type_object( $subtype );
			if ( is_object( $object ) && ! empty( $object->_builtin ) ) {
				return;
			}
			if ( null !== Repository::post_type( $subtype ) || isset( $selected_post_types[ $subtype ] ) ) {
				return;
			}
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			/* translators: %s: required post type key. */
			throw new \InvalidArgumentException( sprintf( __( 'Post type “%s” is a required context but is not WordPress-built-in, Content Models-managed or selected for adoption.', 'core-blueprint' ), $subtype ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( 'term' === $object_type ) {
			$object = get_taxonomy( $subtype );
			if ( is_object( $object ) && ! empty( $object->_builtin ) ) {
				return;
			}
			if ( null !== Repository::taxonomy( $subtype ) || isset( $selected_taxonomies[ $subtype ] ) ) {
				return;
			}
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			/* translators: %s: required taxonomy key. */
			throw new \InvalidArgumentException( sprintf( __( 'Taxonomy “%s” is a required context but is not WordPress-built-in, Content Models-managed or selected for adoption.', 'core-blueprint' ), $subtype ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}
}
