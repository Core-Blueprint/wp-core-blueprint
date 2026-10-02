<?php
declare(strict_types=1);

namespace CB\Core\Snippets;

use CB\Core\Log\AuditLog;
use CB\Core\Snippets\Validation\PhpValidator;

defined( 'ABSPATH' ) || exit;

final class Repository {
	public static function all(): array {
		if ( ! Paths::ensure() ) {
			return [];
		}
		return self::read_registry();
	}

	public static function get( string $id ): ?array {
		$all = self::all();
		return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function code( string $id ): string {
		$meta = self::get( $id );
		if ( null === $meta ) {
			return '';
		}
		$code = self::read_code_for_meta( $meta );
		return is_string( $code ) ? $code : '';
	}

	/**
	 * @return array|\WP_Error Normalized saved metadata or error.
	 */
	public static function save( array $input, string $code ) {
		if ( strlen( $code ) > 1024 * 1024 ) {
			return new \WP_Error( 'cb_snippets_code_too_large', __( 'A managed snippet cannot exceed 1 MB.', 'core-blueprint' ) );
		}

		$id       = isset( $input['id'] ) ? sanitize_key( (string) $input['id'] ) : '';
		$existing = '' !== $id ? self::get( $id ) : null;
		if ( '' === $id ) {
			$id = wp_generate_uuid4();
		}

		$type = sanitize_key( (string) ( $input['type'] ?? ( $existing['type'] ?? 'php' ) ) );
		if ( ! in_array( $type, Schema::TYPES, true ) ) {
			return new \WP_Error( 'cb_snippets_invalid_type', __( 'Unsupported snippet type.', 'core-blueprint' ) );
		}

		$location = sanitize_key( (string) ( $input['location'] ?? Schema::default_location( $type ) ) );
		if ( ! Schema::valid_location( $type, $location ) ) {
			$location = Schema::default_location( $type );
		}

		if ( 'php' === $type ) {
			$error = PhpValidator::validate( $code );
			if ( null !== $error ) {
				return new \WP_Error( 'cb_snippets_php_invalid', $error );
			}
		}

		if ( 'js' === $type && preg_match( '/^\s*<\s*script\b/i', $code ) ) {
			return new \WP_Error( 'cb_snippets_wrapping_tag', __( 'Remove the wrapping <script> tag. Core Blueprint adds it when the snippet is rendered.', 'core-blueprint' ) );
		}
		if ( 'css' === $type && preg_match( '/^\s*<\s*style\b/i', $code ) ) {
			return new \WP_Error( 'cb_snippets_wrapping_tag', __( 'Remove the wrapping <style> tag. Core Blueprint adds it when the snippet is rendered.', 'core-blueprint' ) );
		}

		$now  = gmdate( 'c' );
		$meta = array_replace( Schema::default_meta(), is_array( $existing ) ? $existing : [] );
		$meta['id']          = $id;
		$meta['title']       = sanitize_text_field( (string) ( $input['title'] ?? $meta['title'] ) );
		$meta['description'] = sanitize_textarea_field( (string) ( $input['description'] ?? $meta['description'] ) );
		$meta['type']        = $type;
		$meta['location']    = $location;
		$meta['priority']    = max( 1, min( 999, (int) ( $input['priority'] ?? 10 ) ) );
		$meta['enabled']     = ! empty( $input['enabled'] );
		$meta['shortcode']   = sanitize_key( (string) ( $input['shortcode'] ?? '' ) );
		$meta['tags']        = self::sanitize_tags( $input['tags'] ?? [] );
		$meta['conditions']  = self::sanitize_conditions( $input['conditions'] ?? [] );
		$meta['created_at']  = '' !== (string) $meta['created_at'] ? (string) $meta['created_at'] : $now;
		$meta['updated_at']  = $now;
		$meta['last_error']  = null;
		$meta['code_hash']   = hash( 'sha256', $code );
		$meta['source']      = sanitize_key( (string) ( $input['source'] ?? $meta['source'] ) ) ?: 'core-blueprint';

		if ( '' === $meta['title'] ) {
			return new \WP_Error( 'cb_snippets_title_required', __( 'A snippet title is required.', 'core-blueprint' ) );
		}

		if ( 'shortcode' === $location ) {
			if ( '' === $meta['shortcode'] ) {
				$meta['shortcode'] = 'cb_snippet_' . substr( str_replace( '-', '', $id ), 0, 10 );
			}
			$duplicate = self::find_by_shortcode( $meta['shortcode'], $id );
			if ( null !== $duplicate ) {
				return new \WP_Error( 'cb_snippets_shortcode_duplicate', __( 'That shortcode name is already used by another snippet.', 'core-blueprint' ) );
			}
		} else {
			$meta['shortcode'] = '';
		}

		try {
			return Lock::run( static function () use ( $id, $meta, $code ) {
				$registry = self::read_registry();
				if ( 'shortcode' === (string) $meta['location'] && null !== self::find_by_shortcode_in_registry( $registry, (string) $meta['shortcode'], $id ) ) {
					return new \WP_Error( 'cb_snippets_shortcode_duplicate', __( 'That shortcode name is already used by another snippet.', 'core-blueprint' ) );
				}

				$old_registry  = $registry;
				$path          = Paths::code_file( $id );
				$old_code_file = is_file( $path ) ? file_get_contents( $path ) : null;
				$registry[ $id ] = $meta;

				if ( ! AtomicFile::write( $path, CodeFile::build( (string) $meta['type'], $code ) ) ) {
					throw new \RuntimeException( 'Could not write snippet code file.' );
				}

				if ( ! self::write_registry( $registry ) || ! IndexBuilder::rebuild_from_registry( $registry, State::is_enabled() ) ) {
					self::restore_after_failed_write( $path, $old_code_file, $old_registry );
					throw new \RuntimeException( 'Could not rebuild snippet registry/index.' );
				}

				return $meta;
			} );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'cb_snippets_storage_error', $e->getMessage() );
		}
	}

	/** @return array|\WP_Error */
	public static function duplicate( string $id ) {
		$meta = self::get( $id );
		if ( null === $meta ) {
			return new \WP_Error( 'cb_snippets_not_found', __( 'Snippet not found.', 'core-blueprint' ) );
		}
		$code = self::read_code_for_meta( $meta );
		if ( ! is_string( $code ) ) {
			return new \WP_Error( 'cb_snippets_code_invalid', __( 'The managed snippet code file is missing or invalid.', 'core-blueprint' ) );
		}
		unset( $meta['id'], $meta['created_at'], $meta['updated_at'] );
		/* translators: %s: original managed snippet title. */
		$meta['title']   = sprintf( __( '%s (copy)', 'core-blueprint' ), (string) $meta['title'] );
		$meta['enabled'] = false;
		$meta['shortcode'] = '';
		return self::save( $meta, $code );
	}

	/** @return true|\WP_Error */
	public static function set_enabled( string $id, bool $enabled ) {
		try {
			return Lock::run( static function () use ( $id, $enabled ) {
				$registry = self::read_registry();
				if ( ! isset( $registry[ $id ] ) || ! is_array( $registry[ $id ] ) ) {
					return new \WP_Error( 'cb_snippets_not_found', __( 'Snippet not found.', 'core-blueprint' ) );
				}

				if ( $enabled ) {
					$validation = self::validate_managed_code_file( $registry[ $id ] );
					if ( $validation instanceof \WP_Error ) {
						return $validation;
					}
				}

				$old = $registry;
				$registry[ $id ]['enabled']    = $enabled;
				$registry[ $id ]['updated_at'] = gmdate( 'c' );
				if ( $enabled ) {
					$registry[ $id ]['last_error'] = null;
				}

				if ( ! self::write_registry( $registry ) || ! IndexBuilder::rebuild_from_registry( $registry, State::is_enabled() ) ) {
					self::write_registry( $old );
					IndexBuilder::rebuild_from_registry( $old, State::is_enabled() );
					return new \WP_Error( 'cb_snippets_storage_error', __( 'Could not update snippet state.', 'core-blueprint' ) );
				}
				return true;
			} );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'cb_snippets_storage_error', $e->getMessage() );
		}
	}

	/** @return true|\WP_Error */
	public static function delete( string $id ) {
		try {
			return Lock::run( static function () use ( $id ) {
				$registry = self::read_registry();
				if ( ! isset( $registry[ $id ] ) ) {
					return new \WP_Error( 'cb_snippets_not_found', __( 'Snippet not found.', 'core-blueprint' ) );
				}
				$old = $registry;
				unset( $registry[ $id ] );

				if ( ! self::write_registry( $registry ) || ! IndexBuilder::rebuild_from_registry( $registry, State::is_enabled() ) ) {
					self::write_registry( $old );
					IndexBuilder::rebuild_from_registry( $old, State::is_enabled() );
					return new \WP_Error( 'cb_snippets_storage_error', __( 'Could not delete snippet.', 'core-blueprint' ) );
				}

				$path = Paths::code_file( $id );
				if ( is_file( $path ) ) {
					wp_delete_file( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				return true;
			} );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'cb_snippets_storage_error', $e->getMessage() );
		}
	}

	public static function disable_for_runtime_error( string $id, array $error ): void {
		try {
			Lock::run( static function () use ( $id, $error ): void {
				$registry = self::read_registry();
				if ( ! isset( $registry[ $id ] ) || ! is_array( $registry[ $id ] ) ) {
					return;
				}
				$registry[ $id ]['enabled']    = false;
				$registry[ $id ]['updated_at'] = gmdate( 'c' );
				$registry[ $id ]['last_error'] = [
					'message' => sanitize_text_field( (string) ( $error['message'] ?? 'Runtime error' ) ),
					'file'    => sanitize_text_field( basename( (string) ( $error['file'] ?? '' ) ) ),
					'line'    => (int) ( $error['line'] ?? 0 ),
					'at'      => gmdate( 'c' ),
				];
				self::write_registry( $registry );
				IndexBuilder::rebuild_from_registry( $registry, State::is_enabled() );
			} );

			AuditLog::log( 'snippet_auto_disabled', 'warning', [
				'snippet_id' => $id,
				'error'      => sanitize_text_field( (string) ( $error['message'] ?? 'Runtime error' ) ),
			] );
		} catch ( \Throwable $e ) {
			error_log( 'Core Blueprint Snippets could not auto-disable a failed snippet: ' . $e->getMessage() );
		}
	}

	public static function rebuild_index(): bool {
		try {
			return Lock::run( static function (): bool {
				return IndexBuilder::rebuild_from_registry( self::read_registry(), State::is_enabled() );
			} );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function health(): array {
		$storage_ok  = Paths::ensure() && wp_is_writable( Paths::base_dir() ) && wp_is_writable( Paths::code_dir() );
		$registry_ok = self::array_file_is_valid( Paths::registry() );
		$index_ok    = self::array_file_is_valid( Paths::runtime_index() );
		$code_ok     = $registry_ok ? self::managed_code_files_are_valid( self::read_registry() ) : false;

		return [
			'storage'  => $storage_ok,
			'registry' => $registry_ok,
			'index'    => $index_ok,
			'code'     => $code_ok,
		];
	}

	private static function managed_code_files_are_valid( array $registry ): bool {
		foreach ( $registry as $meta ) {
			if ( ! is_array( $meta ) || self::validate_managed_code_file( $meta ) instanceof \WP_Error ) {
				return false;
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private static function validate_managed_code_file( array $meta ) {
		$id   = sanitize_key( (string) ( $meta['id'] ?? '' ) );
		$type = sanitize_key( (string) ( $meta['type'] ?? '' ) );
		$path = Paths::code_file( $id );
		if ( '' === $id || ! is_file( $path ) ) {
			return new \WP_Error( 'cb_snippets_code_missing', __( 'The managed snippet code file is missing.', 'core-blueprint' ) );
		}

		$code = self::read_code_for_meta( $meta );
		if ( ! is_string( $code ) ) {
			return new \WP_Error( 'cb_snippets_code_invalid', __( 'The managed snippet code file is missing or malformed.', 'core-blueprint' ) );
		}
		$expected_hash = (string) ( $meta['code_hash'] ?? '' );
		if ( '' === $expected_hash || ! hash_equals( $expected_hash, hash( 'sha256', $code ) ) ) {
			return new \WP_Error( 'cb_snippets_code_changed', __( 'The managed snippet code file no longer matches its saved integrity fingerprint. Review and save the snippet again before enabling it.', 'core-blueprint' ) );
		}

		if ( 'php' === $type ) {
			$error = PhpValidator::validate( $code );
			return null === $error ? true : new \WP_Error( 'cb_snippets_php_invalid', $error );
		}
		if ( 'js' === $type && preg_match( '/^\s*<\s*script\b/i', $code ) ) {
			return new \WP_Error( 'cb_snippets_wrapping_tag', __( 'Remove the wrapping <script> tag. Core Blueprint adds it when the snippet is rendered.', 'core-blueprint' ) );
		}
		if ( 'css' === $type && preg_match( '/^\s*<\s*style\b/i', $code ) ) {
			return new \WP_Error( 'cb_snippets_wrapping_tag', __( 'Remove the wrapping <style> tag. Core Blueprint adds it when the snippet is rendered.', 'core-blueprint' ) );
		}

		return true;
	}

	private static function array_file_is_valid( string $path ): bool {
		if ( ! is_file( $path ) ) {
			return true;
		}

		try {
			$data = require $path;
			return is_array( $data );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	private static function read_registry(): array {
		$path = Paths::registry();
		if ( ! is_file( $path ) ) {
			return [];
		}
		try {
			$data = require $path;
			return is_array( $data ) ? $data : [];
		} catch ( \Throwable $e ) {
			error_log( 'Core Blueprint Snippets registry could not be loaded: ' . $e->getMessage() );
			return [];
		}
	}

	private static function write_registry( array $registry ): bool {
		ksort( $registry, SORT_STRING );
		$contents = "<?php\n\ndefined( 'ABSPATH' ) || exit;\nreturn " . var_export( $registry, true ) . ";\n";
		return AtomicFile::write( Paths::registry(), $contents );
	}

	private static function read_code_for_meta( array $meta ): ?string {
		return CodeFile::read(
			(string) ( $meta['type'] ?? '' ),
			Paths::code_file( (string) ( $meta['id'] ?? '' ) )
		);
	}

	private static function restore_after_failed_write( string $path, $old_code_file, array $old_registry ): void {
		if ( is_string( $old_code_file ) ) {
			AtomicFile::write( $path, $old_code_file );
		} elseif ( is_file( $path ) ) {
			wp_delete_file( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		self::write_registry( $old_registry );
		IndexBuilder::rebuild_from_registry( $old_registry, State::is_enabled() );
	}

	private static function find_by_shortcode( string $shortcode, string $exclude_id ): ?array {
		return self::find_by_shortcode_in_registry( self::all(), $shortcode, $exclude_id );
	}

	private static function find_by_shortcode_in_registry( array $registry, string $shortcode, string $exclude_id ): ?array {
		if ( '' === $shortcode ) {
			return null;
		}
		foreach ( $registry as $id => $meta ) {
			if ( (string) $id === $exclude_id || ! is_array( $meta ) ) {
				continue;
			}
			if ( $shortcode === (string) ( $meta['shortcode'] ?? '' ) ) {
				return $meta;
			}
		}
		return null;
	}

	private static function sanitize_tags( $tags ): array {
		if ( is_string( $tags ) ) {
			$tags = preg_split( '/[,\n]+/', $tags ) ?: [];
		}
		if ( ! is_array( $tags ) ) {
			return [];
		}
		$out = [];
		foreach ( $tags as $tag ) {
			$tag = sanitize_text_field( (string) $tag );
			if ( '' !== $tag ) {
				$out[] = $tag;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function sanitize_conditions( $conditions ): array {
		if ( ! is_array( $conditions ) ) {
			return [ 'relation' => 'and', 'rules' => [] ];
		}
		$relation = 'or' === strtolower( (string) ( $conditions['relation'] ?? 'and' ) ) ? 'or' : 'and';
		$rules = [];
		foreach ( (array) ( $conditions['rules'] ?? [] ) as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
				continue;
			}
			$rules[] = [
				'field'    => sanitize_key( (string) $rule['field'] ),
				'operator' => sanitize_key( (string) ( $rule['operator'] ?? 'is' ) ),
				'value'    => is_scalar( $rule['value'] ?? null ) ? sanitize_text_field( (string) $rule['value'] ) : '',
			];
		}
		return [ 'relation' => $relation, 'rules' => $rules ];
	}
}
