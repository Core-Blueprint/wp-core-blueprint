<?php
declare(strict_types=1);
/**
 * Resolve compliance resource assignments to public WordPress URLs.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Compliance;

defined( 'ABSPATH' ) || exit;

final class Resolver {

	/** @return string[] */
	public static function document_mime_types(): array {
		$mimes = [
			'application/pdf',
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.oasis.opendocument.text',
			'application/rtf',
			'text/rtf',
			'text/plain',
		];

		/** Filter public document MIME types accepted by Compliance Resources. */
		$filtered = apply_filters( 'cb_core_compliance_document_mime_types', $mimes );
		if ( ! is_array( $filtered ) ) {
			return $mimes;
		}
		$filtered = array_values( array_unique( array_filter(
			array_map( static fn( $mime ): string => is_string( $mime ) ? trim( $mime ) : '', $filtered )
		) ) );
		return [] === $filtered ? $mimes : $filtered;
	}

	/**
	 * Parse and validate a form value such as page:123 or document:456.
	 * Empty input intentionally means no assignment.
	 *
	 * @return array{type:string,object_id:int}|null
	 */
	public static function parse_reference( string $raw ): ?array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( 1 !== preg_match( '/^(page|document):(\d+)$/', $raw, $matches ) ) {
			return null;
		}
		$reference = [
			'type'      => $matches[1],
			'object_id' => (int) $matches[2],
		];
		return self::is_valid_reference( $reference ) ? $reference : null;
	}

	/** @param array<string,mixed> $reference */
	public static function is_valid_reference( array $reference ): bool {
		$type      = isset( $reference['type'] ) && is_string( $reference['type'] ) ? $reference['type'] : '';
		$object_id = isset( $reference['object_id'] ) ? absint( $reference['object_id'] ) : 0;
		if ( 0 === $object_id ) {
			return false;
		}

		$post = get_post( $object_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( 'page' === $type ) {
			return 'page' === $post->post_type && 'publish' === $post->post_status;
		}
		if ( 'document' !== $type || 'attachment' !== $post->post_type ) {
			return false;
		}

		$mime = (string) get_post_mime_type( $object_id );
		if ( ! in_array( $mime, self::document_mime_types(), true ) ) {
			return false;
		}
		$url = wp_get_attachment_url( $object_id );
		return is_string( $url ) && '' !== $url;
	}

	/** @param array{type:string,object_id:int}|null $reference */
	public static function reference_value( ?array $reference ): string {
		if ( null === $reference || ! self::is_valid_reference( $reference ) ) {
			return '';
		}
		return $reference['type'] . ':' . (int) $reference['object_id'];
	}

	/** @param array{type:string,object_id:int} $reference */
	public static function url_for_reference( array $reference ): string {
		if ( ! self::is_valid_reference( $reference ) ) {
			return '';
		}
		if ( 'page' === $reference['type'] ) {
			$url = get_permalink( (int) $reference['object_id'] );
		} else {
			$url = wp_get_attachment_url( (int) $reference['object_id'] );
		}
		return is_string( $url ) ? $url : '';
	}

	/** @param array{type:string,object_id:int} $reference */
	public static function label_for_reference( array $reference ): string {
		if ( ! self::is_valid_reference( $reference ) ) {
			return '';
		}
		$post = get_post( (int) $reference['object_id'] );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		if ( 'page' === $reference['type'] ) {
			$title = get_the_title( $post );
			return '' !== trim( (string) $title ) ? (string) $title : sprintf( __( 'Page #%d', 'core-blueprint' ), $post->ID );
		}
		$path = get_attached_file( $post->ID );
		if ( is_string( $path ) && '' !== $path ) {
			return wp_basename( $path );
		}
		$title = get_the_title( $post );
		return '' !== trim( (string) $title ) ? (string) $title : sprintf( __( 'Document #%d', 'core-blueprint' ), $post->ID );
	}


	/**
	 * Presentation item for a stored assignment, including unavailable objects.
	 *
	 * This never changes resolution validity. It exists only so the admin UI can
	 * explain which object remains assigned after a Page becomes draft/private or
	 * a document becomes unavailable.
	 *
	 * @param array{type:string,object_id:int}|null $reference
	 * @return array{id:string,label:string,meta:string}|null
	 */
	public static function assignment_item( ?array $reference ): ?array {
		if ( null === $reference ) {
			return null;
		}
		$type      = isset( $reference['type'] ) && is_string( $reference['type'] ) ? $reference['type'] : '';
		$object_id = isset( $reference['object_id'] ) ? absint( $reference['object_id'] ) : 0;
		if ( 0 === $object_id || ! in_array( $type, [ 'page', 'document' ], true ) ) {
			return null;
		}

		$post = get_post( $object_id );
		if ( 'page' === $type ) {
			$label = sprintf( __( 'Page #%d', 'core-blueprint' ), $object_id );
			$meta  = __( 'Unavailable page', 'core-blueprint' );
			if ( $post instanceof \WP_Post && 'page' === $post->post_type ) {
				$title = get_the_title( $post );
				if ( '' !== trim( (string) $title ) ) {
					$label = (string) $title;
				}
				if ( 'publish' === $post->post_status ) {
					$meta = __( 'Published page', 'core-blueprint' );
				} else {
					$status = get_post_status_object( $post->post_status );
					$status_label = is_object( $status ) && isset( $status->label )
						? trim( (string) $status->label )
						: '';
					$meta = '' !== $status_label
						? sprintf( __( '%s page', 'core-blueprint' ), $status_label )
						: __( 'Unavailable page', 'core-blueprint' );
				}
			}
			return [
				'id'    => 'page:' . $object_id,
				'label' => $label,
				'meta'  => $meta,
			];
		}

		$label = sprintf( __( 'Document #%d', 'core-blueprint' ), $object_id );
		$meta  = __( 'Unavailable document', 'core-blueprint' );
		if ( $post instanceof \WP_Post && 'attachment' === $post->post_type ) {
			$path = get_attached_file( $post->ID );
			if ( is_string( $path ) && '' !== $path ) {
				$label = wp_basename( $path );
			} else {
				$title = get_the_title( $post );
				if ( '' !== trim( (string) $title ) ) {
					$label = (string) $title;
				}
			}
			if ( self::is_valid_reference( $reference ) ) {
				$meta = __( 'Document', 'core-blueprint' );
			}
		}
		return [
			'id'    => 'document:' . $object_id,
			'label' => $label,
			'meta'  => $meta,
		];
	}

	/**
	 * Object Picker representation for a valid reference.
	 *
	 * @param array{type:string,object_id:int}|null $reference
	 * @return array{id:string,label:string,meta:string}|null
	 */
	public static function picker_item( ?array $reference ): ?array {
		if ( null === $reference || ! self::is_valid_reference( $reference ) ) {
			return null;
		}
		return self::assignment_item( $reference );
	}

	/**
	 * Resolve one resource for a requested/current locale with default fallback.
	 *
	 * @return array{reference:array{type:string,object_id:int},url:string,locale:string,used_locale:string}|null
	 */
	public static function resolve( string $key, ?string $locale = null ): ?array {
		if ( null === ResourceRegistry::get( $key ) ) {
			return null;
		}

		$assignment = Repository::assignment( $key );
		$requested  = self::normalize_locale( null === $locale || '' === $locale ? determine_locale() : $locale );
		$reference  = null;
		$used       = '';

		if ( '' !== $requested ) {
			foreach ( $assignment['locales'] as $candidate => $candidate_reference ) {
				if ( self::normalize_locale( (string) $candidate ) === $requested && is_array( $candidate_reference ) ) {
					$reference = $candidate_reference;
					$used      = (string) $candidate;
					break;
				}
			}

			if ( null === $reference ) {
				$language = strtolower( (string) strtok( $requested, '_' ) );
				foreach ( $assignment['locales'] as $candidate => $candidate_reference ) {
					$normalized = self::normalize_locale( (string) $candidate );
					if ( '' !== $normalized && strtolower( (string) strtok( $normalized, '_' ) ) === $language && ! str_contains( $normalized, '_' ) && is_array( $candidate_reference ) ) {
						$reference = $candidate_reference;
						$used      = (string) $candidate;
						break;
					}
				}
			}
		}

		if ( null === $reference && is_array( $assignment['default'] ) ) {
			$reference = $assignment['default'];
			$used      = 'default';
		}

		// Respect an existing WordPress Privacy Policy on first use without
		// taking ownership of or rewriting WordPress' own setting.
		if ( null === $reference && ResourceRegistry::key( ResourceRegistry::BASE_OWNER, 'privacy-policy' ) === $key ) {
			$wp_privacy_page = absint( get_option( 'wp_page_for_privacy_policy', 0 ) );
			$fallback = [ 'type' => 'page', 'object_id' => $wp_privacy_page ];
			if ( $wp_privacy_page > 0 && self::is_valid_reference( $fallback ) ) {
				$reference = $fallback;
				$used      = 'wordpress';
			}
		}

		if ( ! is_array( $reference ) || ! self::is_valid_reference( $reference ) ) {
			return null;
		}
		$url = self::url_for_reference( $reference );
		if ( '' === $url ) {
			return null;
		}

		return [
			'reference'   => $reference,
			'url'         => $url,
			'locale'      => $requested,
			'used_locale' => $used,
		];
	}

	public static function url( string $key, ?string $locale = null ): string {
		$resolved = self::resolve( $key, $locale );
		return null === $resolved ? '' : $resolved['url'];
	}

	/** Normalize a manual/WordPress locale without depending on multilingual plugins. */
	public static function normalize_locale( string $locale ): string {
		$locale = str_replace( '-', '_', trim( $locale ) );
		if ( '' === $locale || 1 !== preg_match( '/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8})*$/', $locale ) ) {
			return '';
		}
		$parts = explode( '_', $locale );
		$parts[0] = strtolower( $parts[0] );
		for ( $i = 1, $count = count( $parts ); $i < $count; $i++ ) {
			$parts[ $i ] = 2 === strlen( $parts[ $i ] ) && ctype_alpha( $parts[ $i ] )
				? strtoupper( $parts[ $i ] )
				: ucfirst( strtolower( $parts[ $i ] ) );
		}
		return implode( '_', $parts );
	}
}
