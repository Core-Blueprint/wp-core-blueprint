<?php
declare(strict_types=1);
/**
 * Field type registry, normalization and value sanitization.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\ContentModels;

defined( 'ABSPATH' ) || exit;

final class FieldTypes {
	/** @return array<string,string> */
	public static function labels(): array {
		return [
			'text'       => __( 'Text', 'core-blueprint' ),
			'textarea'   => __( 'Textarea', 'core-blueprint' ),
			'wysiwyg'     => __( 'WYSIWYG', 'core-blueprint' ),
			'number'     => __( 'Number', 'core-blueprint' ),
			'email'      => __( 'Email', 'core-blueprint' ),
			'url'        => __( 'URL', 'core-blueprint' ),
			'date'       => __( 'Date', 'core-blueprint' ),
			'time'       => __( 'Time', 'core-blueprint' ),
			'datetime'   => __( 'Date & Time', 'core-blueprint' ),
			'color'      => __( 'Color', 'core-blueprint' ),
			'true_false' => __( 'True / False', 'core-blueprint' ),
			'select'     => __( 'Select', 'core-blueprint' ),
			'radio'      => __( 'Radio', 'core-blueprint' ),
			'checkbox'   => __( 'Checkbox', 'core-blueprint' ),
			'image'      => __( 'Image', 'core-blueprint' ),
			'file'       => __( 'File', 'core-blueprint' ),
			'gallery'       => __( 'Gallery', 'core-blueprint' ),
			'group'         => __( 'Group', 'core-blueprint' ),
			'repeater'      => __( 'Repeater', 'core-blueprint' ),
			'post_relation'  => __( 'Post / Object Relation', 'core-blueprint' ),
			'user_relation'  => __( 'User Relation', 'core-blueprint' ),
			'term_relation'  => __( 'Taxonomy / Term Relation', 'core-blueprint' ),
		];
	}

	/** @return array<string,array<string,string>> */
	public static function grouped_labels(): array {
		$labels = self::labels();
		$groups = [
			__( 'Basic', 'core-blueprint' ) => [ 'text', 'textarea', 'wysiwyg', 'number', 'email', 'url', 'date', 'time', 'datetime', 'color', 'true_false' ],
			__( 'Choice', 'core-blueprint' ) => [ 'select', 'radio', 'checkbox' ],
			__( 'Media', 'core-blueprint' ) => [ 'image', 'file', 'gallery' ],
			__( 'Structured', 'core-blueprint' ) => [ 'group', 'repeater' ],
			__( 'Relations', 'core-blueprint' ) => [ 'post_relation', 'user_relation', 'term_relation' ],
		];

		$grouped = [];
		foreach ( $groups as $group_label => $types ) {
			foreach ( $types as $type ) {
				if ( isset( $labels[ $type ] ) ) {
					$grouped[ $group_label ][ $type ] = $labels[ $type ];
				}
			}
		}

		return $grouped;
	}

	public static function exists( string $type ): bool {
		return isset( self::labels()[ $type ] );
	}

	/** @return array<string,string> */
	public static function parse_choices( string $raw ): array {
		$choices = [];
		foreach ( preg_split( '/\R/u', $raw ) ?: [] as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( ':', $line, 2 ) );
			$value = sanitize_text_field( $parts[0] ?? '' );
			$label = sanitize_text_field( $parts[1] ?? $value );
			if ( '' !== $value ) {
				$choices[ $value ] = '' !== $label ? $label : $value;
			}
		}
		return $choices;
	}

	/** @param array<string,string> $choices */
	public static function choices_to_text( array $choices ): string {
		$lines = [];
		foreach ( $choices as $value => $label ) {
			$lines[] = $value === $label ? $value : $value . ' : ' . $label;
		}
		return implode( "\n", $lines );
	}

	/** @return mixed */
	public static function sanitize_value( array $field, $value ) {
		$type = (string) ( $field['type'] ?? 'text' );
		switch ( $type ) {
			case 'textarea':
				return sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );
			case 'wysiwyg':
				return wp_kses_post( is_scalar( $value ) ? (string) $value : '' );
			case 'number':
				if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
					return '';
				}
				$number = (float) $value;
				if ( isset( $field['min'] ) && '' !== (string) $field['min'] ) {
					$number = max( $number, (float) $field['min'] );
				}
				if ( isset( $field['max'] ) && '' !== (string) $field['max'] ) {
					$number = min( $number, (float) $field['max'] );
				}
				return $number;
			case 'email':
				return sanitize_email( is_scalar( $value ) ? (string) $value : '' );
			case 'url':
				return esc_url_raw( is_scalar( $value ) ? (string) $value : '' );
			case 'date':
				$value = is_scalar( $value ) ? (string) $value : '';
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
			case 'time':
				$value = is_scalar( $value ) ? (string) $value : '';
				return preg_match( '/^\d{2}:\d{2}(?::\d{2})?$/', $value ) ? $value : '';
			case 'datetime':
				$value = is_scalar( $value ) ? (string) $value : '';
				return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/', $value ) ? $value : '';
			case 'color':
				return sanitize_hex_color( is_scalar( $value ) ? (string) $value : '' ) ?: '';
			case 'true_false':
				return ! empty( $value );
			case 'select':
			case 'radio':
				$value = is_scalar( $value ) ? (string) $value : '';
				$choices = is_array( $field['choices'] ?? null ) ? $field['choices'] : [];
				return isset( $choices[ $value ] ) ? $value : '';
			case 'checkbox':
				$choices = is_array( $field['choices'] ?? null ) ? $field['choices'] : [];
				$values = is_array( $value ) ? $value : [];
				return array_values( array_filter(
					array_map( static fn( $item ): string => sanitize_text_field( (string) $item ), $values ),
					static fn( string $item ): bool => isset( $choices[ $item ] )
				) );
			case 'group':
			case 'repeater':
				return self::sanitize_structured_value( $field, $value );
			case 'post_relation':
			case 'user_relation':
			case 'term_relation':
				return self::sanitize_relation_value( $field, $value );
			case 'image':
				return self::sanitize_attachment_id( $value, true );
			case 'file':
				return self::sanitize_attachment_id( $value, false );
			case 'gallery':
				$values = is_array( $value ) ? $value : ( is_scalar( $value ) ? preg_split( '/\s*,\s*/', (string) $value ) : [] );
				$ids = [];
				foreach ( $values ?: [] as $item ) {
					$id = self::sanitize_attachment_id( $item, true );
					if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
						$ids[] = $id;
					}
				}
				return $ids;
			case 'text':
			default:
				return sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		}
	}



	public static function contains_media( array $field ): bool {
		$type = (string) ( $field['type'] ?? '' );
		if ( in_array( $type, [ 'image', 'file', 'gallery' ], true ) ) {
			return true;
		}
		if ( self::is_structured_type( $type ) ) {
			foreach ( self::sub_fields( $field ) as $sub_field ) {
				if ( self::contains_media( $sub_field ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function contains_relations( array $field ): bool {
		$type = (string) ( $field['type'] ?? '' );
		if ( self::is_relation_type( $type ) ) {
			return true;
		}
		if ( self::is_structured_type( $type ) ) {
			foreach ( self::sub_fields( $field ) as $sub_field ) {
				if ( self::contains_relations( $sub_field ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function is_structured_type( string $type ): bool {
		return in_array( $type, [ 'group', 'repeater' ], true );
	}

	/** @return array<string,array<string,mixed>> */
	public static function sub_fields( array $field ): array {
		$sub_fields = is_array( $field['sub_fields'] ?? null ) ? $field['sub_fields'] : [];
		$result = [];
		foreach ( $sub_fields as $key => $sub_field ) {
			if ( ! is_array( $sub_field ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $sub_field['id'] ?? $key ) );
			if ( '' === $id ) {
				continue;
			}
			$result[ $id ] = $sub_field;
		}
		return $result;
	}

	/** @return mixed */
	private static function sanitize_structured_value( array $field, $value ) {
		$type = (string) ( $field['type'] ?? '' );
		$sub_fields = self::sub_fields( $field );
		if ( 'group' === $type ) {
			$row = is_array( $value ) ? $value : [];
			return self::sanitize_structured_row( $sub_fields, $row, false );
		}

		$rows = is_array( $value ) ? $value : [];
		$sanitized = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = self::sanitize_structured_row( $sub_fields, $row, true );
			if ( self::structured_row_is_empty( $clean, $sub_fields ) ) {
				continue;
			}
			$sanitized[] = $clean;
		}
		return array_values( $sanitized );
	}

	/** @param array<string,array<string,mixed>> $sub_fields @return array<string,mixed> */
	private static function sanitize_structured_row( array $sub_fields, array $row, bool $with_row_id ): array {
		$clean = [];
		if ( $with_row_id ) {
			$row_id = sanitize_key( (string) ( $row['_cb_row_id'] ?? '' ) );
			if ( '' === $row_id ) {
				$row_id = 'row_' . str_replace( '-', '', wp_generate_uuid4() );
			}
			$clean['_cb_row_id'] = $row_id;
		}
		foreach ( $sub_fields as $sub_field ) {
			$name = sanitize_key( (string) ( $sub_field['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$raw = array_key_exists( $name, $row ) ? $row[ $name ] : self::empty_input_value( $sub_field );
			$clean[ $name ] = self::sanitize_value( $sub_field, $raw );
		}
		return $clean;
	}

	/** @param array<string,array<string,mixed>> $sub_fields */
	private static function structured_row_is_empty( array $row, array $sub_fields ): bool {
		foreach ( $sub_fields as $sub_field ) {
			$name = (string) ( $sub_field['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$value = $row[ $name ] ?? null;
			if ( ! self::value_is_empty( $sub_field, $value ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return mixed */
	public static function empty_input_value( array $field ) {
		$type = (string) ( $field['type'] ?? 'text' );
		if ( 'true_false' === $type ) {
			return '0';
		}
		if ( in_array( $type, [ 'checkbox', 'gallery', 'repeater' ], true ) || ( self::is_relation_type( $type ) && ! empty( $field['relation_multiple'] ) ) ) {
			return [];
		}
		if ( 'group' === $type ) {
			return [];
		}
		return '';
	}

	/** @param mixed $value */
	public static function value_is_empty( array $field, $value ): bool {
		$type = (string) ( $field['type'] ?? '' );
		if ( 'true_false' === $type ) {
			return ! (bool) $value;
		}
		if ( in_array( $type, [ 'image', 'file' ], true ) || ( self::is_relation_type( $type ) && empty( $field['relation_multiple'] ) ) ) {
			return absint( $value ) <= 0;
		}
		if ( self::is_relation_type( $type ) && ! empty( $field['relation_multiple'] ) ) {
			return [] === self::relation_raw_ids( $value );
		}
		if ( 'group' === $type ) {
			return self::structured_row_is_empty( is_array( $value ) ? $value : [], self::sub_fields( $field ) );
		}
		if ( 'repeater' === $type ) {
			return ! is_array( $value ) || [] === $value;
		}
		return null === $value || '' === $value || [] === $value;
	}

	/**
	 * Evaluate a field's Conditional Logic against a submitted sibling field set.
	 *
	 * Rules inside a group use AND; groups use OR. Missing source fields make a
	 * rule fail closed. This mirrors the editor runtime while preserving hidden
	 * field values server-side by letting callers skip inactive fields entirely.
	 *
	 * @param array<string,mixed>              $field
	 * @param array<int|string,array<string,mixed>> $siblings
	 * @param array<string,mixed>              $submitted
	 */
	public static function conditional_is_active( array $field, array $siblings, array $submitted ): bool {
		$groups = is_array( $field['conditional_logic'] ?? null ) ? $field['conditional_logic'] : [];
		if ( [] === $groups ) {
			return true;
		}

		$field_map = [];
		foreach ( $siblings as $candidate_id => $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $candidate['id'] ?? $candidate_id ) );
			if ( '' !== $id ) {
				$field_map[ $id ] = $candidate;
			}
		}

		foreach ( $groups as $rules ) {
			if ( ! is_array( $rules ) || [] === $rules ) {
				continue;
			}
			$matches = true;
			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) || ! self::conditional_rule_matches( $rule, $field_map, $submitted ) ) {
					$matches = false;
					break;
				}
			}
			if ( $matches ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $rule @param array<string,array<string,mixed>> $field_map @param array<string,mixed> $submitted */
	private static function conditional_rule_matches( array $rule, array $field_map, array $submitted ): bool {
		$source_id = sanitize_key( (string) ( $rule['field'] ?? '' ) );
		if ( '' === $source_id || ! isset( $field_map[ $source_id ] ) ) {
			return false;
		}
		$source = $field_map[ $source_id ];
		$name = (string) ( $source['name'] ?? '' );
		if ( '' === $name ) {
			return false;
		}
		$raw = array_key_exists( $name, $submitted ) ? $submitted[ $name ] : self::empty_input_value( $source );
		$value = self::sanitize_value( $source, $raw );
		$operator = (string) ( $rule['operator'] ?? 'equals' );
		$expected = (string) ( $rule['value'] ?? '' );
		$empty = self::value_is_empty( $source, $value );

		if ( 'empty' === $operator ) {
			return $empty;
		}
		if ( 'not_empty' === $operator ) {
			return ! $empty;
		}

		if ( is_array( $value ) ) {
			$equals = in_array( $expected, array_map( 'strval', $value ), true );
		} elseif ( is_bool( $value ) ) {
			$equals = ( $value ? '1' : '0' ) === $expected;
		} else {
			$equals = (string) $value === $expected;
		}
		return 'not_equals' === $operator ? ! $equals : $equals;
	}

	/** @return array<string,mixed> */
	public static function rest_schema( array $field ): array {
		$type = (string) ( $field['type'] ?? 'text' );
		if ( 'group' === $type || 'repeater' === $type ) {
			$properties = [];
			foreach ( self::sub_fields( $field ) as $sub_field ) {
				$name = (string) ( $sub_field['name'] ?? '' );
				if ( '' !== $name ) {
					$properties[ $name ] = self::rest_value_schema( $sub_field );
				}
			}
			$row_schema = [ 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false ];
			if ( 'repeater' === $type ) {
				$row_schema['properties']['_cb_row_id'] = [ 'type' => 'string' ];
				return [ 'type' => 'array', 'items' => $row_schema ];
			}
			return $row_schema;
		}
		return self::rest_value_schema( $field );
	}

	/** @return array<string,mixed> */
	private static function rest_value_schema( array $field ): array {
		$type = (string) ( $field['type'] ?? 'text' );
		if ( in_array( $type, [ 'checkbox' ], true ) ) {
			return [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ];
		}
		if ( 'gallery' === $type || ( self::is_relation_type( $type ) && ! empty( $field['relation_multiple'] ) ) ) {
			return [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ];
		}
		if ( in_array( $type, [ 'image', 'file' ], true ) || self::is_relation_type( $type ) ) {
			return [ 'type' => 'integer' ];
		}
		if ( 'number' === $type ) {
			return [ 'type' => 'number' ];
		}
		if ( 'true_false' === $type ) {
			return [ 'type' => 'boolean' ];
		}
		return [ 'type' => 'string' ];
	}


	public static function is_relation_type( string $type ): bool {
		return in_array( $type, [ 'post_relation', 'user_relation', 'term_relation' ], true );
	}

	/** @param mixed $value @return int[] */
	public static function relation_raw_ids( $value ): array {
		$values = is_array( $value ) ? $value : ( is_scalar( $value ) ? preg_split( '/\s*,\s*/', (string) $value ) : [] );
		$ids = [];
		foreach ( $values ?: [] as $item ) {
			$id = absint( is_scalar( $item ) ? $item : 0 );
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/** @param mixed $value @return int|int[] */
	private static function sanitize_relation_value( array $field, $value ) {
		$ids = self::relation_raw_ids( $value );
		$valid = [];
		foreach ( $ids as $id ) {
			if ( self::relation_id_is_allowed( $field, $id ) ) {
				$valid[] = $id;
			}
		}
		if ( ! empty( $field['relation_multiple'] ) ) {
			return $valid;
		}
		return $valid[0] ?? 0;
	}

	private static function relation_id_is_allowed( array $field, int $id ): bool {
		$type = (string) ( $field['type'] ?? '' );
		if ( 'post_relation' === $type ) {
			$post = get_post( $id );
			$allowed = array_values( array_filter( array_map( 'sanitize_key', (array) ( $field['relation_post_types'] ?? [] ) ) ) );
			return $post instanceof \WP_Post && ! empty( $allowed ) && in_array( $post->post_type, $allowed, true );
		}
		if ( 'user_relation' === $type ) {
			$user = get_userdata( $id );
			if ( ! $user instanceof \WP_User ) {
				return false;
			}
			$allowed_roles = array_values( array_filter( array_map( 'sanitize_key', (array) ( $field['relation_roles'] ?? [] ) ) ) );
			return empty( $allowed_roles ) || (bool) array_intersect( $allowed_roles, (array) $user->roles );
		}
		if ( 'term_relation' === $type ) {
			$term = get_term( $id );
			$allowed = array_values( array_filter( array_map( 'sanitize_key', (array) ( $field['relation_taxonomies'] ?? [] ) ) ) );
			return $term instanceof \WP_Term && ! empty( $allowed ) && in_array( $term->taxonomy, $allowed, true );
		}
		return false;
	}

	/**
	 * Resolve stored relation IDs into display-safe picker items.
	 * Missing targets remain visible as unavailable IDs instead of breaking the editor.
	 *
	 * @param mixed $value
	 * @return array<int,array{id:int,label:string,meta:string}>
	 */
	public static function relation_selected_items( array $field, $value ): array {
		$items = [];
		foreach ( self::relation_raw_ids( $value ) as $id ) {
			$type = (string) ( $field['type'] ?? '' );
			if ( 'post_relation' === $type ) {
				$post = get_post( $id );
				if ( $post instanceof \WP_Post ) {
					$post_type = get_post_type_object( $post->post_type );
					$title = get_the_title( $post );
					$items[] = [
						'id'    => $id,
						/* translators: %d: referenced WordPress object ID. */
				'label' => '' !== trim( (string) $title ) ? (string) $title : sprintf( __( '(no title) #%d', 'core-blueprint' ), $id ),
						'meta'  => sprintf( '%s · #%d', $post_type ? (string) $post_type->labels->singular_name : $post->post_type, $id ),
					];
					continue;
				}
			} elseif ( 'user_relation' === $type ) {
				$user = get_userdata( $id );
				if ( $user instanceof \WP_User ) {
					$items[] = [ 'id' => $id, 'label' => (string) $user->display_name, 'meta' => __( 'User', 'core-blueprint' ) . ' · #' . $id ];
					continue;
				}
			} elseif ( 'term_relation' === $type ) {
				$term = get_term( $id );
				if ( $term instanceof \WP_Term ) {
					$taxonomy = get_taxonomy( $term->taxonomy );
					$items[] = [ 'id' => $id, 'label' => (string) $term->name, 'meta' => sprintf( '%s · #%d', $taxonomy ? (string) $taxonomy->labels->singular_name : $term->taxonomy, $id ) ];
					continue;
				}
			}
			/* translators: %d: unavailable referenced object ID. */
			$items[] = [ 'id' => $id, 'label' => sprintf( __( 'Unavailable item #%d', 'core-blueprint' ), $id ), 'meta' => __( 'The referenced object no longer exists or is no longer allowed.', 'core-blueprint' ) ];
		}
		return $items;
	}


	/** @param mixed $value */
	private static function sanitize_attachment_id( $value, bool $image_only ): int {
		$id = absint( is_scalar( $value ) ? $value : 0 );
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return 0;
		}
		if ( $image_only && ! wp_attachment_is_image( $id ) ) {
			return 0;
		}
		return $id;
	}

	/** @return array<string,mixed> */
	public static function meta_args( array $field, string $object_type = 'post' ): array {
		$type = (string) ( $field['type'] ?? 'text' );
		$relation_multiple = self::is_relation_type( $type ) && ! empty( $field['relation_multiple'] );
		$meta_type = match ( $type ) {
			'number'      => 'number',
			'true_false'  => 'boolean',
			'image',
			'file'        => 'integer',
			'post_relation',
			'user_relation',
			'term_relation' => $relation_multiple ? 'array' : 'integer',
			'group'       => 'object',
			'repeater'    => 'array',
			'checkbox',
			'gallery'     => 'array',
			default       => 'string',
		};

		$show_in_rest = false;
		if ( ! empty( $field['show_in_rest'] ) ) {
			if ( self::is_structured_type( $type ) ) {
				$show_in_rest = [ 'schema' => self::rest_schema( $field ) ];
			} else {
			$show_in_rest = match ( $type ) {
				'checkbox' => [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ],
				'gallery'  => [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ] ],
				'post_relation',
				'user_relation',
				'term_relation' => $relation_multiple
					? [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ] ]
					: [ 'schema' => [ 'type' => 'integer' ] ],
				default    => true,
			};
			}
		}

		$args = [
			'type'              => $meta_type,
			'single'            => true,
			'show_in_rest'      => $show_in_rest,
			'sanitize_callback' => static fn( $value ) => self::sanitize_value( $field, $value ),
			'auth_callback'     => static function ( $allowed, $meta_key, $object_id ) use ( $object_type ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
				return match ( $object_type ) {
					'term' => current_user_can( 'edit_term', (int) $object_id ),
					'user' => current_user_can( 'edit_user', (int) $object_id ),
					default => current_user_can( 'edit_post', (int) $object_id ),
				};
			},
		];

		$default = self::meta_default( $field );
		if ( 'number' !== $type || is_numeric( $default ) ) {
			$args['default'] = $default;
		}
		return $args;
	}

	/** @return array<string,mixed> */
	public static function setting_args( array $field ): array {
		$meta = self::meta_args( $field );
		$args = [
			'type'              => (string) ( $meta['type'] ?? 'string' ),
			'sanitize_callback' => static fn( $value ) => self::sanitize_value( $field, $value ),
			'show_in_rest'      => $meta['show_in_rest'] ?? false,
		];
		if ( array_key_exists( 'default', $meta ) ) {
			$args['default'] = $meta['default'];
		}
		return $args;
	}

	/** @return mixed */
	private static function meta_default( array $field ) {
		$type = (string) ( $field['type'] ?? 'text' );
		if ( in_array( $type, [ 'checkbox', 'gallery', 'group', 'repeater' ], true ) || ( self::is_relation_type( $type ) && ! empty( $field['relation_multiple'] ) ) ) {
			$raw = is_array( $field['default_value'] ?? null ) ? $field['default_value'] : [];
			return self::sanitize_value( $field, $raw );
		}
		if ( 'true_false' === $type ) {
			return ! empty( $field['default_value'] );
		}
		if ( in_array( $type, [ 'image', 'file' ], true ) || ( self::is_relation_type( $type ) && empty( $field['relation_multiple'] ) ) ) {
			return self::sanitize_value( $field, $field['default_value'] ?? 0 );
		}
		return self::sanitize_value( $field, $field['default_value'] ?? '' );
	}
}
