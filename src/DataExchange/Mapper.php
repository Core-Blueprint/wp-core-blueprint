<?php
declare(strict_types=1);
/**
 * Core Blueprint Data Mapper engine.
 *
 * Converts source-shaped records into target-shaped records without owning any
 * extension business semantics. Data Exchange remains responsible for canonical
 * provider validation, preview planning and apply.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\DataExchange;

use CB\Core\ExtensionRegistry;
use JsonException;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Mapper {

	private const MAX_FIELD_KEY_LEN     = 191;
	private const MAX_FIELD_LABEL_LEN   = 120;
	private const MAX_DESCRIPTION_LEN   = 500;
	private const MAX_ALIASES_PER_FIELD = 32;
	private const MAX_ALIAS_LEN         = 191;
	private const MAX_DEPTH             = 16;
	private const MAX_CONTAINER_SIZE    = 10000;

	private const FIELD_TYPES = [
		'string',
		'integer',
		'number',
		'boolean',
		'date',
		'datetime',
		'enum',
		'reference',
		'json',
	];

	/**
	 * Normalize one extension-owned mapping schema.
	 *
	 * @param list<array<string,mixed>> $fields
	 * @return list<array{id:string,label:string,type:string,required:bool,readable:bool,writable:bool,aliases:list<string>,description:string}>|WP_Error
	 */
	public static function normalize_schema( array $fields ): array|WP_Error {
		if ( ! array_is_list( $fields ) || [] === $fields || count( $fields ) > Foundation::MAX_CSV_COLUMNS ) {
			return new WP_Error( 'cb_core_data_mapper_invalid_schema', 'Data Mapper schema must be a bounded non-empty field list.' );
		}

		$out  = [];
		$seen = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || array_is_list( $field ) ) {
				return new WP_Error( 'cb_core_data_mapper_invalid_schema', 'Data Mapper field definition is invalid.' );
			}
			$allowed = [ 'id', 'label', 'type', 'required', 'readable', 'writable', 'aliases', 'description' ];
			if ( [] !== array_diff( array_keys( $field ), $allowed ) ) {
				return new WP_Error( 'cb_core_data_mapper_invalid_schema', 'Data Mapper field definition contains unsupported properties.' );
			}
			foreach ( [ 'required', 'readable', 'writable' ] as $flag ) {
				if ( array_key_exists( $flag, $field ) && ! is_bool( $field[ $flag ] ) ) {
					return new WP_Error( 'cb_core_data_mapper_invalid_schema', 'Data Mapper field boolean metadata must use actual boolean values.' );
				}
			}

			$id = isset( $field['id'] ) && is_string( $field['id'] ) ? self::field_key( $field['id'] ) : null;
			if ( null === $id || isset( $seen[ $id ] ) ) {
				return new WP_Error( 'cb_core_data_mapper_invalid_schema', 'Data Mapper field identifiers must be valid and unique.' );
			}
			$seen[ $id ] = true;

			$label = isset( $field['label'] ) && is_string( $field['label'] )
				? trim( wp_strip_all_tags( $field['label'] ) )
				: $id;
			$type = isset( $field['type'] ) && is_string( $field['type'] ) ? trim( $field['type'] ) : 'string';
			$description = isset( $field['description'] ) && is_string( $field['description'] )
				? trim( wp_strip_all_tags( $field['description'] ) )
				: '';
			if (
				'' === $label
				|| strlen( $label ) > self::MAX_FIELD_LABEL_LEN
				|| ! in_array( $type, self::FIELD_TYPES, true )
				|| strlen( $description ) > self::MAX_DESCRIPTION_LEN
			) {
				return new WP_Error( 'cb_core_data_mapper_invalid_schema', 'Data Mapper field metadata is invalid.' );
			}

			$readable = array_key_exists( 'readable', $field ) ? $field['readable'] : true;
			$writable = array_key_exists( 'writable', $field ) ? $field['writable'] : true;
			$required = array_key_exists( 'required', $field ) ? $field['required'] : false;
			if ( ! $readable && ! $writable ) {
				return new WP_Error( 'cb_core_data_mapper_invalid_schema', 'Data Mapper fields must be readable, writable or both.' );
			}

			$aliases = $field['aliases'] ?? [];
			if ( ! is_array( $aliases ) || ! array_is_list( $aliases ) || count( $aliases ) > self::MAX_ALIASES_PER_FIELD ) {
				return new WP_Error( 'cb_core_data_mapper_invalid_schema', 'Data Mapper field aliases are invalid.' );
			}
			$clean_aliases = [];
			foreach ( $aliases as $alias ) {
				if ( ! is_string( $alias ) ) {
					return new WP_Error( 'cb_core_data_mapper_invalid_schema', 'Data Mapper field aliases are invalid.' );
				}
				$alias = trim( wp_strip_all_tags( $alias ) );
				if ( '' === $alias || strlen( $alias ) > self::MAX_ALIAS_LEN || str_contains( $alias, "\0" ) ) {
					return new WP_Error( 'cb_core_data_mapper_invalid_schema', 'Data Mapper field aliases are invalid.' );
				}
				$clean_aliases[ $alias ] = true;
			}

			$out[] = [
				'id'          => $id,
				'label'       => $label,
				'type'        => $type,
				'required'    => $required,
				'readable'    => $readable,
				'writable'    => $writable,
				'aliases'     => array_keys( $clean_aliases ),
				'description' => $description,
			];
		}
		return $out;
	}

	/**
	 * Resolve and normalize the current mapping schema from one provider object.
	 *
	 * @return list<array<string,mixed>>|WP_Error
	 */
	public static function entity_schema( MappingEntityInterface $entity, int $schema_version ): array|WP_Error {
		if ( $schema_version < 1 ) {
			return new WP_Error( 'cb_core_data_mapper_invalid_schema_version', 'Data Mapper schema version is invalid.' );
		}
		try {
			if ( ! $entity->supports_schema_version( $schema_version ) ) {
				return new WP_Error( 'cb_core_data_mapper_unsupported_schema', 'Data Mapper entity does not support this schema version.' );
			}
			$fields = $entity->mapping_fields( $schema_version );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return new WP_Error( 'cb_core_data_mapper_provider_failed', 'Data Mapper provider failed while declaring its field schema.' );
		}
		return is_wp_error( $fields ) ? $fields : self::normalize_schema( $fields );
	}

	/**
	 * Parse a generic CSV into a source schema plus bounded records.
	 *
	 * No provider semantics are applied here. The resulting records must still be
	 * mapped into a canonical entity schema and pass Data Exchange preflight.
	 *
	 * @return array{delimiter:string,fields:list<array<string,mixed>>,records:list<array<string,string>>,record_count:int}|WP_Error
	 */
	public static function inspect_csv( string $input, string $delimiter = 'auto' ): array|WP_Error {
		if ( strlen( $input ) > Foundation::MAX_INPUT_BYTES ) {
			return new WP_Error( 'cb_core_data_mapper_input_too_large', 'Data Mapper input exceeds the transport limit.' );
		}
		if ( str_contains( $input, "\0" ) ) {
			return new WP_Error( 'cb_core_data_mapper_invalid_input', 'Data Mapper input contains invalid null bytes.' );
		}
		if ( str_starts_with( $input, "\xEF\xBB\xBF" ) ) {
			$input = substr( $input, 3 );
		}
		$delimiter = self::delimiter( $input, $delimiter );
		if ( is_wp_error( $delimiter ) ) {
			return $delimiter;
		}

		$stream = fopen( 'php://temp', 'w+b' );
		if ( false === $stream || false === fwrite( $stream, $input ) ) {
			if ( is_resource( $stream ) ) {
				fclose( $stream );
			}
			return new WP_Error( 'cb_core_data_mapper_csv_failed', 'Could not open the Data Mapper CSV transport.' );
		}
		rewind( $stream );
		$header = fgetcsv( $stream, 0, $delimiter, '"', '' );
		if ( ! is_array( $header ) || [] === $header || count( $header ) > Foundation::MAX_CSV_COLUMNS ) {
			fclose( $stream );
			return new WP_Error( 'cb_core_data_mapper_invalid_csv', 'Data Mapper CSV header is invalid.' );
		}

		$headers = [];
		$seen    = [];
		foreach ( $header as $raw ) {
			$key = is_string( $raw ) ? self::field_key( $raw ) : null;
			if ( null === $key || isset( $seen[ $key ] ) ) {
				fclose( $stream );
				return new WP_Error( 'cb_core_data_mapper_invalid_csv', 'Data Mapper CSV headers must be non-empty and unique.' );
			}
			$headers[]  = $key;
			$seen[ $key ] = true;
		}

		$records = [];
		while ( false !== ( $row = fgetcsv( $stream, 0, $delimiter, '"', '' ) ) ) {
			if ( [ null ] === $row ) {
				continue;
			}
			if ( count( $row ) !== count( $headers ) ) {
				fclose( $stream );
				return new WP_Error( 'cb_core_data_mapper_invalid_csv', 'Data Mapper CSV contains a row with a different column count.' );
			}
			if ( count( $records ) >= Foundation::MAX_RECORDS ) {
				fclose( $stream );
				return new WP_Error( 'cb_core_data_mapper_too_many_records', 'Data Mapper record count exceeds the transport limit.' );
			}
			$record = [];
			foreach ( $headers as $offset => $key ) {
				$value = (string) $row[ $offset ];
				if ( str_contains( $value, "\0" ) ) {
					fclose( $stream );
					return new WP_Error( 'cb_core_data_mapper_invalid_csv', 'Data Mapper CSV contains invalid null bytes.' );
				}
				$record[ $key ] = $value;
			}
			$records[] = $record;
		}
		fclose( $stream );

		$fields = [];
		foreach ( $headers as $header_key ) {
			$fields[] = [
				'id'          => $header_key,
				'label'       => $header_key,
				'type'        => 'string',
				'required'    => false,
				'readable'    => true,
				'writable'    => false,
				'aliases'     => [],
				'description' => '',
			];
		}

		return [
			'delimiter'    => $delimiter,
			'fields'       => $fields,
			'records'      => $records,
			'record_count' => count( $records ),
		];
	}

	/**
	 * Deterministically suggest direct mappings. No fuzzy/semantic guessing is
	 * performed: suggestions require an exact normalized id/label/alias match.
	 *
	 * @param list<array<string,mixed>> $source_fields
	 * @param list<array<string,mixed>> $target_fields
	 * @return list<array{source:?string,target:?string,transform:string,value:mixed}>|WP_Error
	 */
	public static function suggest( array $source_fields, array $target_fields ): array|WP_Error {
		$source = self::normalize_schema( $source_fields );
		$target = self::normalize_schema( $target_fields );
		if ( is_wp_error( $source ) || is_wp_error( $target ) ) {
			return is_wp_error( $source ) ? $source : $target;
		}

		$target_tokens = [];
		foreach ( $target as $field ) {
			if ( ! $field['writable'] ) {
				continue;
			}
			foreach ( self::field_tokens( $field ) as $token ) {
				$target_tokens[ $token ][ $field['id'] ] = true;
			}
		}

		$out     = [];
		$claimed = [];
		foreach ( $source as $field ) {
			if ( ! $field['readable'] ) {
				continue;
			}
			$candidates = [];
			foreach ( self::field_tokens( $field ) as $token ) {
				foreach ( array_keys( $target_tokens[ $token ] ?? [] ) as $target_id ) {
					if ( ! isset( $claimed[ $target_id ] ) ) {
						$candidates[ $target_id ] = true;
					}
				}
			}
			$matches = array_keys( $candidates );
			if ( 1 === count( $matches ) ) {
				$target_id             = $matches[0];
				$claimed[ $target_id ] = true;
				$out[] = [ 'source' => $field['id'], 'target' => $target_id, 'transform' => Foundation::MAP_DIRECT, 'value' => null ];
				continue;
			}
			$out[] = [ 'source' => $field['id'], 'target' => null, 'transform' => Foundation::MAP_IGNORE, 'value' => null ];
		}
		return $out;
	}

	/**
	 * Validate and fingerprint one mapping definition.
	 *
	 * @param list<array<string,mixed>> $mapping
	 * @return array{valid:bool,mapping:list<array{source:?string,target:?string,transform:string,value:mixed}>,errors:list<array{index:?int,code:string,message:string}>,counts:array{direct:int,constant:int,ignore:int},fingerprint:string}|WP_Error
	 */
	public static function plan( array $source_fields, array $target_fields, array $mapping, bool $require_required = true ): array|WP_Error {
		$source = self::normalize_schema( $source_fields );
		$target = self::normalize_schema( $target_fields );
		if ( is_wp_error( $source ) || is_wp_error( $target ) ) {
			return is_wp_error( $source ) ? $source : $target;
		}
		if ( ! array_is_list( $mapping ) || count( $mapping ) > Foundation::MAX_CSV_COLUMNS * 2 ) {
			return new WP_Error( 'cb_core_data_mapper_invalid_mapping', 'Data Mapper mapping must be a bounded list.' );
		}

		$source_by_id = self::fields_by_id( $source );
		$target_by_id = self::fields_by_id( $target );
		$seen_sources = [];
		$seen_targets = [];
		$normalized   = [];
		$errors       = [];
		$counts       = [ Foundation::MAP_DIRECT => 0, Foundation::MAP_CONSTANT => 0, Foundation::MAP_IGNORE => 0 ];

		foreach ( $mapping as $index => $entry ) {
			if ( ! is_array( $entry ) || array_is_list( $entry ) ) {
				$errors[] = self::mapping_error( $index, 'cb_core_data_mapper_invalid_mapping', 'Data Mapper mapping entry is invalid.' );
				continue;
			}
			$allowed = [ 'source', 'target', 'transform', 'value' ];
			if ( [] !== array_diff( array_keys( $entry ), $allowed ) ) {
				$errors[] = self::mapping_error( $index, 'cb_core_data_mapper_invalid_mapping', 'Data Mapper mapping entry contains unsupported properties.' );
				continue;
			}
			$source_id = isset( $entry['source'] ) && is_string( $entry['source'] ) ? self::field_key( $entry['source'] ) : null;
			$target_id = isset( $entry['target'] ) && is_string( $entry['target'] ) ? self::field_key( $entry['target'] ) : null;
			$transform = isset( $entry['transform'] ) && is_string( $entry['transform'] ) ? $entry['transform'] : '';
			$value     = $entry['value'] ?? null;
			if ( $transform !== trim( $transform ) || ! Foundation::is_mapping_transform( $transform ) ) {
				$errors[] = self::mapping_error( $index, 'cb_core_data_mapper_invalid_transform', 'Data Mapper mapping transform is invalid.' );
				continue;
			}

			if ( Foundation::MAP_DIRECT === $transform ) {
				if (
					null === $source_id || null === $target_id
					|| ! isset( $source_by_id[ $source_id ], $target_by_id[ $target_id ] )
					|| ! $source_by_id[ $source_id ]['readable'] || ! $target_by_id[ $target_id ]['writable']
					|| isset( $seen_sources[ $source_id ] ) || isset( $seen_targets[ $target_id ] )
				) {
					$errors[] = self::mapping_error( $index, 'cb_core_data_mapper_invalid_direct_mapping', 'Data Mapper direct mapping is invalid or duplicates a mapped field.' );
					continue;
				}
				$seen_sources[ $source_id ] = true;
				$seen_targets[ $target_id ] = true;
				$normalized[] = [ 'source' => $source_id, 'target' => $target_id, 'transform' => $transform, 'value' => null ];
				++$counts[ $transform ];
				continue;
			}

			if ( Foundation::MAP_IGNORE === $transform ) {
				if ( null === $source_id || ! isset( $source_by_id[ $source_id ] ) || ! $source_by_id[ $source_id ]['readable'] || isset( $seen_sources[ $source_id ] ) || null !== $target_id ) {
					$errors[] = self::mapping_error( $index, 'cb_core_data_mapper_invalid_ignore_mapping', 'Data Mapper ignore mapping is invalid or duplicates a source field.' );
					continue;
				}
				$seen_sources[ $source_id ] = true;
				$normalized[] = [ 'source' => $source_id, 'target' => null, 'transform' => $transform, 'value' => null ];
				++$counts[ $transform ];
				continue;
			}

			if ( null !== $source_id || null === $target_id || ! isset( $target_by_id[ $target_id ] ) || ! $target_by_id[ $target_id ]['writable'] || isset( $seen_targets[ $target_id ] ) || ! self::transport_safe( $value ) ) {
				$errors[] = self::mapping_error( $index, 'cb_core_data_mapper_invalid_constant_mapping', 'Data Mapper constant mapping is invalid or duplicates a target field.' );
				continue;
			}
			$seen_targets[ $target_id ] = true;
			$normalized[] = [ 'source' => null, 'target' => $target_id, 'transform' => $transform, 'value' => $value ];
			++$counts[ $transform ];
		}

		if ( $require_required ) {
			foreach ( $target as $field ) {
				if ( $field['required'] && $field['writable'] && ! isset( $seen_targets[ $field['id'] ] ) ) {
					$errors[] = self::mapping_error( null, 'cb_core_data_mapper_required_unmapped', sprintf( 'Required target field is not mapped: %s.', $field['label'] ) );
				}
			}
		}

		$fingerprint = '';
		if ( [] === $errors ) {
			try {
				$encoded_mapping = json_encode( $normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			} catch ( JsonException $exception ) {
				unset( $exception );
				return new WP_Error( 'cb_core_data_mapper_json_failed', 'Could not fingerprint the Data Mapper plan.' );
			}
			if ( strlen( $encoded_mapping ) > Foundation::MAX_INPUT_BYTES ) {
				return new WP_Error( 'cb_core_data_mapper_mapping_too_large', 'Data Mapper mapping exceeds the transport limit.' );
			}
			$fingerprint = hash( 'sha256', $encoded_mapping );
		}

		return [
			'valid'       => [] === $errors,
			'mapping'     => $normalized,
			'errors'      => $errors,
			'counts'      => [
				'direct'   => $counts[ Foundation::MAP_DIRECT ],
				'constant' => $counts[ Foundation::MAP_CONSTANT ],
				'ignore'   => $counts[ Foundation::MAP_IGNORE ],
			],
			'fingerprint' => $fingerprint,
		];
	}

	/**
	 * Apply a valid mapping to bounded records.
	 *
	 * @param list<array<string,mixed>> $records
	 * @param list<array<string,mixed>> $mapping
	 * @return list<array<string,mixed>>|WP_Error
	 */
	public static function map_records( array $records, array $source_fields, array $target_fields, array $mapping, bool $require_required = true ): array|WP_Error {
		if ( ! array_is_list( $records ) || count( $records ) > Foundation::MAX_RECORDS ) {
			return new WP_Error( 'cb_core_data_mapper_invalid_records', 'Data Mapper records must be a bounded list.' );
		}
		$plan = self::plan( $source_fields, $target_fields, $mapping, $require_required );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( ! $plan['valid'] ) {
			return new WP_Error( 'cb_core_data_mapper_invalid_mapping', 'Data Mapper mapping contains errors.', [ 'plan' => $plan ] );
		}

		$target = self::normalize_schema( $target_fields );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$target_by_id = self::fields_by_id( $target );
		$out          = [];
		$output_bytes = 0;
		foreach ( $records as $index => $record ) {
			if ( ! is_array( $record ) || array_is_list( $record ) || ! self::transport_safe( $record ) ) {
				return new WP_Error( 'cb_core_data_mapper_invalid_record', sprintf( 'Data Mapper record %d is invalid.', $index ) );
			}
			$mapped = [];
			foreach ( $plan['mapping'] as $entry ) {
				if ( Foundation::MAP_IGNORE === $entry['transform'] ) {
					continue;
				}
				$target_id = $entry['target'];
				if ( Foundation::MAP_CONSTANT === $entry['transform'] ) {
					$mapped[ $target_id ] = $entry['value'];
					continue;
				}
				$source_id = $entry['source'];
				if ( ! array_key_exists( $source_id, $record ) ) {
					if ( $target_by_id[ $target_id ]['required'] ) {
						return new WP_Error( 'cb_core_data_mapper_missing_source_value', sprintf( 'Data Mapper record %d is missing a value required by target field %s.', $index, $target_by_id[ $target_id ]['label'] ) );
					}
					continue;
				}
				$mapped[ $target_id ] = $record[ $source_id ];
			}
			if ( [] === $mapped ) {
				return new WP_Error( 'cb_core_data_mapper_empty_record', sprintf( 'Data Mapper record %d produced no target fields.', $index ) );
			}
			try {
				$encoded_record = json_encode( $mapped, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			} catch ( JsonException $exception ) {
				unset( $exception );
				return new WP_Error( 'cb_core_data_mapper_json_failed', sprintf( 'Could not encode Data Mapper record %d.', $index ) );
			}
			$output_bytes += strlen( $encoded_record );
			if ( $output_bytes > Foundation::MAX_INPUT_BYTES ) {
				return new WP_Error( 'cb_core_data_mapper_output_too_large', 'Mapped Data Exchange output exceeds the transport limit.' );
			}
			$out[] = $mapped;
		}
		return $out;
	}

	/**
	 * Wrap already-mapped canonical records in the Data Exchange JSON envelope.
	 *
	 * @param list<array<string,mixed>> $records
	 */
	public static function exchange_json( string $extension_id, string $entity_id, int $schema_version, array $records ): string|WP_Error {
		if ( $extension_id !== trim( $extension_id ) || ! ExtensionRegistry::is_valid_id( $extension_id ) ) {
			return new WP_Error( 'cb_core_data_mapper_invalid_extension', 'Data Mapper extension id is invalid.' );
		}
		if ( $entity_id !== trim( $entity_id ) || 1 !== preg_match( '/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $entity_id ) || $schema_version < 1 ) {
			return new WP_Error( 'cb_core_data_mapper_invalid_entity', 'Data Mapper entity identity or schema version is invalid.' );
		}
		if ( ! array_is_list( $records ) || count( $records ) > Foundation::MAX_RECORDS ) {
			return new WP_Error( 'cb_core_data_mapper_invalid_records', 'Data Mapper records must be a bounded list.' );
		}
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || [] === $record || array_is_list( $record ) || ! self::transport_safe( $record ) ) {
				return new WP_Error( 'cb_core_data_mapper_invalid_record', 'Data Mapper produced an invalid canonical record.' );
			}
		}

		$envelope = [
			'format'         => Foundation::FORMAT_ID,
			'format_version' => Foundation::FORMAT_VERSION,
			'extension_id'   => $extension_id,
			'entity'         => $entity_id,
			'schema_version' => $schema_version,
			'exported_at'    => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
			'records'        => $records,
		];
		try {
			$json = json_encode( $envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} catch ( JsonException $exception ) {
			unset( $exception );
			return new WP_Error( 'cb_core_data_mapper_json_failed', 'Could not encode mapped Data Exchange JSON.' );
		}
		return strlen( $json ) <= Foundation::MAX_INPUT_BYTES
			? $json
			: new WP_Error( 'cb_core_data_mapper_output_too_large', 'Mapped Data Exchange output exceeds the transport limit.' );
	}

	/** @param list<array<string,mixed>> $fields @return array<string,array<string,mixed>> */
	private static function fields_by_id( array $fields ): array {
		$out = [];
		foreach ( $fields as $field ) {
			$out[ $field['id'] ] = $field;
		}
		return $out;
	}

	/** @param array<string,mixed> $field @return list<string> */
	private static function field_tokens( array $field ): array {
		$tokens = [];
		foreach ( [ $field['id'], $field['label'], ...$field['aliases'] ] as $candidate ) {
			$token = self::match_key( (string) $candidate );
			if ( '' !== $token ) {
				$tokens[ $token ] = true;
			}
		}
		return array_keys( $tokens );
	}

	private static function match_key( string $value ): string {
		$value = strtolower( remove_accents( trim( wp_strip_all_tags( $value ) ) ) );
		return (string) preg_replace( '/[^a-z0-9]+/', '', $value );
	}

	private static function field_key( string $value ): ?string {
		$value = trim( $value );
		if (
			'' === $value
			|| strlen( $value ) > self::MAX_FIELD_KEY_LEN
			|| str_contains( $value, "\0" )
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value )
		) {
			return null;
		}
		return $value;
	}

	private static function delimiter( string $input, string $requested ): string|WP_Error {
		if ( 'auto' !== $requested ) {
			return in_array( $requested, [ ',', ';', "\t" ], true )
				? $requested
				: new WP_Error( 'cb_core_data_mapper_invalid_delimiter', 'Data Mapper CSV delimiter is invalid.' );
		}
		$line = preg_split( '/\r\n|\n|\r/', $input, 2 )[0] ?? '';
		$best = ',';
		$best_count = 0;
		foreach ( [ ',', ';', "\t" ] as $candidate ) {
			$count = count( str_getcsv( $line, $candidate, '"', '' ) );
			if ( $count > $best_count ) {
				$best       = $candidate;
				$best_count = $count;
			}
		}
		return $best;
	}

	/** @return array{index:?int,code:string,message:string} */
	private static function mapping_error( ?int $index, string $code, string $message ): array {
		return [ 'index' => $index, 'code' => $code, 'message' => $message ];
	}

	private static function transport_safe( mixed $value, int $depth = 0 ): bool {
		if ( $depth > self::MAX_DEPTH ) {
			return false;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_string( $value ) ) {
			return ! is_string( $value ) || ! str_contains( $value, "\0" );
		}
		if ( is_float( $value ) ) {
			return is_finite( $value );
		}
		if ( ! is_array( $value ) || count( $value ) > self::MAX_CONTAINER_SIZE ) {
			return false;
		}
		foreach ( $value as $key => $child ) {
			if ( ! is_int( $key ) && ! is_string( $key ) ) {
				return false;
			}
			if ( is_string( $key ) && null === self::field_key( $key ) ) {
				return false;
			}
			if ( ! self::transport_safe( $child, $depth + 1 ) ) {
				return false;
			}
		}
		return true;
	}

	private function __construct() {}
}
