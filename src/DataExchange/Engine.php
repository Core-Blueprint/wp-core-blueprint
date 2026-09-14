<?php
declare(strict_types=1);
/**
 * Core Blueprint Data Exchange runtime engine.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\DataExchange;

use CB\Core\Interoperability\Registry;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Engine {

	private const MAX_DEPTH             = 16;
	private const MAX_CONTAINER_SIZE    = 10000;
	private const MAX_REFERENCE_LEN     = 191;
	private const MAX_WARNING_COUNT     = 50;
	private const MAX_WARNING_LEN       = 500;
	private const MAX_ERROR_CODE_LEN    = 100;
	private const MAX_ERROR_MESSAGE_LEN = 1000;

	/** @return array<string,array<string,mixed>> */
	public static function entities( array $supports = [] ): array {
		return Registry::discover(
			Foundation::CONTRACT_OWNER,
			Foundation::CONTRACT_ID,
			Foundation::CONTRACT_VERSION,
			$supports
		);
	}

	public static function export_json( string $extension_id, string $entity_id, array $context = [] ): string|WP_Error {
		$resolved = self::resolve_entity( $extension_id, $entity_id, Foundation::SUPPORT_EXPORT, Foundation::SUPPORT_JSON, $context, false );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		[ , $entity ] = $resolved;
		$schema_version = self::current_schema_version( $entity );
		if ( is_wp_error( $schema_version ) ) {
			return $schema_version;
		}
		$records = self::export_records( $entity, $context );
		if ( is_wp_error( $records ) ) {
			return $records;
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
		$json = self::encode_json( $envelope );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return strlen( $json ) <= Foundation::MAX_INPUT_BYTES
			? $json
			: new WP_Error( 'cb_core_data_exchange_output_too_large', 'Data Exchange output exceeds the transport limit.' );
	}

	public static function export_csv( string $extension_id, string $entity_id, array $context = [] ): string|WP_Error {
		$resolved = self::resolve_entity( $extension_id, $entity_id, Foundation::SUPPORT_EXPORT, Foundation::SUPPORT_CSV, $context, false );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		[ , $entity ] = $resolved;
		if ( ! $entity instanceof CsvEntityInterface ) {
			return new WP_Error( 'cb_core_data_exchange_csv_contract', 'Data Exchange entity declares CSV support without implementing the CSV contract.' );
		}
		$schema_version = self::current_schema_version( $entity );
		if ( is_wp_error( $schema_version ) ) {
			return $schema_version;
		}
		$columns = self::csv_columns( $entity, $schema_version );
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}
		$records = self::export_records( $entity, $context );
		if ( is_wp_error( $records ) ) {
			return $records;
		}

		$stream = fopen( 'php://temp', 'w+b' );
		if ( false === $stream ) {
			return new WP_Error( 'cb_core_data_exchange_csv_failed', 'Could not open the Data Exchange CSV transport.' );
		}

		$headers = [ 'cb_row_type', 'cb_format', 'cb_format_version', 'cb_extension_id', 'cb_entity', 'cb_schema_version', ...$columns ];
		if ( false === fputcsv( $stream, $headers, ',', '"', '' ) ) {
			fclose( $stream );
			return new WP_Error( 'cb_core_data_exchange_csv_failed', 'Could not encode the Data Exchange CSV header.' );
		}
		$metadata = [ 'meta', Foundation::FORMAT_ID, (string) Foundation::FORMAT_VERSION, $extension_id, $entity_id, (string) $schema_version ];
		$metadata = [ ...$metadata, ...array_fill( 0, count( $columns ), '' ) ];
		if ( false === fputcsv( $stream, $metadata, ',', '"', '' ) ) {
			fclose( $stream );
			return new WP_Error( 'cb_core_data_exchange_csv_failed', 'Could not encode the Data Exchange CSV metadata.' );
		}

		foreach ( $records as $record ) {
			try {
				$row = $entity->to_csv_row( $record );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
				fclose( $stream );
				return new WP_Error( 'cb_core_data_exchange_provider_failed', 'Data Exchange provider failed while mapping a CSV record.' );
			}
			if ( is_wp_error( $row ) ) {
				fclose( $stream );
				return self::bounded_provider_error( $row );
			}
			$normalized = self::normalize_csv_export_row( $row, $columns );
			if ( is_wp_error( $normalized ) ) {
				fclose( $stream );
				return $normalized;
			}
			$values = [ 'data', '', '', '', '', '' ];
			foreach ( $columns as $column ) {
				$values[] = self::protect_csv_cell( $normalized[ $column ] );
			}
			if ( false === fputcsv( $stream, $values, ',', '"', '' ) ) {
				fclose( $stream );
				return new WP_Error( 'cb_core_data_exchange_csv_failed', 'Could not encode a Data Exchange CSV record.' );
			}
		}

		rewind( $stream );
		$output = stream_get_contents( $stream );
		fclose( $stream );
		if ( ! is_string( $output ) ) {
			return new WP_Error( 'cb_core_data_exchange_csv_failed', 'Could not read the Data Exchange CSV transport.' );
		}
		return strlen( $output ) <= Foundation::MAX_INPUT_BYTES
			? $output
			: new WP_Error( 'cb_core_data_exchange_output_too_large', 'Data Exchange output exceeds the transport limit.' );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function preview_json( string $input, string $mode, array $context = [] ): array|WP_Error {
		$decoded = self::decode_json_envelope( $input );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		return self::preview_decoded( $decoded, $mode, $context, Foundation::SUPPORT_JSON );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function apply_json( string $input, string $mode, string $expected_fingerprint, array $context = [] ): array|WP_Error {
		$decoded = self::decode_json_envelope( $input );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		return self::apply_decoded( $decoded, $mode, $expected_fingerprint, $context, Foundation::SUPPORT_JSON );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function preview_csv( string $input, string $mode, array $context = [] ): array|WP_Error {
		$decoded = self::decode_csv_envelope( $input, $context );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		return self::preview_decoded( $decoded, $mode, $context, Foundation::SUPPORT_CSV );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function apply_csv( string $input, string $mode, string $expected_fingerprint, array $context = [] ): array|WP_Error {
		$decoded = self::decode_csv_envelope( $input, $context );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		return self::apply_decoded( $decoded, $mode, $expected_fingerprint, $context, Foundation::SUPPORT_CSV );
	}

	/** @return array<string,mixed>|WP_Error */
	private static function preview_decoded( array $decoded, string $mode, array $context, string $format_support ): array|WP_Error {
		$prepared = self::prepare_import( $decoded, $mode, $context, $format_support );
		return is_wp_error( $prepared ) ? $prepared : $prepared['preview'];
	}

	/** @return array<string,mixed>|WP_Error */
	private static function apply_decoded( array $decoded, string $mode, string $expected_fingerprint, array $context, string $format_support ): array|WP_Error {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_fingerprint ) ) {
			return new WP_Error( 'cb_core_data_exchange_invalid_fingerprint', 'Data Exchange import fingerprint is invalid.' );
		}
		$prepared = self::prepare_import( $decoded, $mode, $context, $format_support );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$preview = $prepared['preview'];
		if ( false === $preview['valid'] ) {
			return new WP_Error( 'cb_core_data_exchange_invalid_records', 'Data Exchange import contains invalid records.', [ 'preview' => $preview ] );
		}
		if ( ! hash_equals( $preview['fingerprint'], $expected_fingerprint ) ) {
			return new WP_Error( 'cb_core_data_exchange_stale_plan', 'Data Exchange import plan changed after preview. Preview the file again before applying it.' );
		}

		/** @var EntityInterface $entity */
		$entity  = $prepared['entity'];
		$results = [];
		$applied = 0;
		$skipped = 0;
		foreach ( $prepared['plans'] as $index => $plan ) {
			if ( Foundation::OP_SKIP === $plan['operation'] ) {
				++$skipped;
				$results[] = [ 'index' => $index, 'operation' => Foundation::OP_SKIP, 'reference' => $plan['reference'] ];
				continue;
			}
			try {
				$result = $entity->apply_import( $plan, $context );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
				$result = new WP_Error( 'cb_core_data_exchange_provider_failed', 'Data Exchange provider failed while applying an import record.' );
			}
			if ( is_wp_error( $result ) ) {
				$error = self::bounded_provider_error( $result );
				return [
					'status'        => 0 === $applied ? 'failed' : 'partial',
					'fingerprint'   => $preview['fingerprint'],
					'record_count'  => $preview['record_count'],
					'applied_count' => $applied,
					'skipped_count' => $skipped,
					'failed_index'  => $index,
					'error'         => [ 'code' => $error->get_error_code(), 'message' => $error->get_error_message() ],
					'items'         => $results,
				];
			}
			if ( ! is_array( $result ) ) {
				return [
					'status'        => 0 === $applied ? 'failed' : 'partial',
					'fingerprint'   => $preview['fingerprint'],
					'record_count'  => $preview['record_count'],
					'applied_count' => $applied,
					'skipped_count' => $skipped,
					'failed_index'  => $index,
					'error'         => [ 'code' => 'cb_core_data_exchange_apply_contract', 'message' => 'Data Exchange provider returned an invalid apply result.' ],
					'items'         => $results,
				];
			}

			$reference = $plan['reference'];
			if ( array_key_exists( 'reference', $result ) ) {
				$reference = is_string( $result['reference'] ) ? self::reference( $result['reference'] ) : null;
				if ( null === $reference ) {
					return [
						'status'        => 0 === $applied ? 'failed' : 'partial',
						'fingerprint'   => $preview['fingerprint'],
						'record_count'  => $preview['record_count'],
						'applied_count' => $applied,
						'skipped_count' => $skipped,
						'failed_index'  => $index,
						'error'         => [ 'code' => 'cb_core_data_exchange_apply_contract', 'message' => 'Data Exchange provider returned an invalid apply reference.' ],
						'items'         => $results,
					];
				}
			}
			++$applied;
			$results[] = [ 'index' => $index, 'operation' => $plan['operation'], 'reference' => $reference ];
		}

		return [
			'status'        => 'complete',
			'fingerprint'   => $preview['fingerprint'],
			'record_count'  => $preview['record_count'],
			'applied_count' => $applied,
			'skipped_count' => $skipped,
			'items'         => $results,
		];
	}

	/**
	 * @return array{entity:EntityInterface,plans:list<array<string,mixed>>,preview:array<string,mixed>}|WP_Error
	 */
	private static function prepare_import( array $decoded, string $mode, array $context, string $format_support ): array|WP_Error {
		if ( $mode !== trim( $mode ) || ! Foundation::is_import_mode( $mode ) ) {
			return new WP_Error( 'cb_core_data_exchange_invalid_mode', 'Data Exchange import mode is invalid.' );
		}
		$extension_id   = (string) $decoded['extension_id'];
		$entity_id      = (string) $decoded['entity'];
		$schema_version = (int) $decoded['schema_version'];
		$resolved = self::resolve_entity( $extension_id, $entity_id, Foundation::SUPPORT_IMPORT, $format_support, $context, true );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		[ , $entity ] = $resolved;

		try {
			$supported = $entity->supports_schema_version( $schema_version );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return new WP_Error( 'cb_core_data_exchange_provider_failed', 'Data Exchange provider failed while checking the source schema version.' );
		}
		if ( ! $supported ) {
			return new WP_Error( 'cb_core_data_exchange_unsupported_schema', 'Data Exchange source schema version is not supported by this entity.' );
		}

		$plans      = [];
		$items      = [];
		$errors     = [];
		$references = [];
		$plan_bytes = 0;
		$counts     = [ Foundation::OP_CREATE => 0, Foundation::OP_UPDATE => 0, Foundation::OP_SKIP => 0 ];
		$records    = $decoded['records'];
		foreach ( $records as $index => $record ) {
			if ( ! is_array( $record ) || [] === $record || array_is_list( $record ) || ! self::transport_safe( $record ) ) {
				$errors[] = [ 'index' => $index, 'code' => 'cb_core_data_exchange_invalid_record', 'message' => 'Data Exchange record is not a valid transport object.' ];
				continue;
			}
			try {
				$plan = $entity->plan_import( $record, $mode, $schema_version, $context );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
				$plan = new WP_Error( 'cb_core_data_exchange_provider_failed', 'Data Exchange provider failed while planning an import record.' );
			}
			if ( is_wp_error( $plan ) ) {
				$error = self::bounded_provider_error( $plan );
				$errors[] = [ 'index' => $index, 'code' => $error->get_error_code(), 'message' => $error->get_error_message() ];
				continue;
			}
			$plan = self::normalize_plan( $plan, $mode );
			if ( is_wp_error( $plan ) ) {
				$errors[] = [ 'index' => $index, 'code' => $plan->get_error_code(), 'message' => $plan->get_error_message() ];
				continue;
			}
			$encoded_plan = self::encode_json( self::canonicalize( $plan ) );
			if ( is_wp_error( $encoded_plan ) ) {
				return $encoded_plan;
			}
			$plan_bytes += strlen( $encoded_plan );
			if ( $plan_bytes > Foundation::MAX_INPUT_BYTES ) {
				return new WP_Error( 'cb_core_data_exchange_plan_too_large', 'Data Exchange provider import plans exceed the transport limit.' );
			}
			if ( isset( $references[ $plan['reference'] ] ) ) {
				$errors[] = [ 'index' => $index, 'code' => 'cb_core_data_exchange_duplicate_reference', 'message' => 'Data Exchange import contains the same portable reference more than once.' ];
				continue;
			}
			$references[ $plan['reference'] ] = true;
			$plans[ $index ] = $plan;
			++$counts[ $plan['operation'] ];
			$items[] = [
				'index'     => $index,
				'operation' => $plan['operation'],
				'reference' => $plan['reference'],
				'warnings'  => $plan['warnings'],
			];
		}

		$valid = [] === $errors && count( $plans ) === count( $records );
		$fingerprint = '';
		if ( $valid ) {
			$fingerprint_source = [
				'format_version' => Foundation::FORMAT_VERSION,
				'extension_id'   => $extension_id,
				'entity'         => $entity_id,
				'schema_version' => $schema_version,
				'mode'           => $mode,
				'plans'          => array_values( $plans ),
			];
			$encoded = self::encode_json( self::canonicalize( $fingerprint_source ) );
			if ( is_wp_error( $encoded ) ) {
				return $encoded;
			}
			$fingerprint = hash( 'sha256', $encoded );
		}

		return [
			'entity' => $entity,
			'plans'  => array_values( $plans ),
			'preview' => [
				'valid'          => $valid,
				'format_version' => Foundation::FORMAT_VERSION,
				'extension_id'   => $extension_id,
				'entity'         => $entity_id,
				'schema_version' => $schema_version,
				'mode'           => $mode,
				'record_count'   => count( $records ),
				'counts'         => $counts,
				'items'          => $items,
				'errors'         => $errors,
				'fingerprint'    => $fingerprint,
			],
		];
	}

	/** @return array{0:array<string,mixed>,1:EntityInterface}|WP_Error */
	private static function resolve_entity(
		string $extension_id,
		string $entity_id,
		string $operation_support,
		string $format_support,
		array $context,
		bool $for_import
	): array|WP_Error {
		if ( ! Registry::is_ready() ) {
			return new WP_Error( 'cb_core_data_exchange_not_ready', 'Data Exchange discovery is not available before the Core Blueprint interoperability lifecycle is ready.' );
		}
		if ( $extension_id !== trim( $extension_id ) || $entity_id !== trim( $entity_id ) ) {
			return new WP_Error( 'cb_core_data_exchange_invalid_identity', 'Data Exchange extension and entity identifiers must be canonical.' );
		}
		$descriptor = Registry::implementation(
			Foundation::CONTRACT_OWNER,
			Foundation::CONTRACT_ID,
			Foundation::CONTRACT_VERSION,
			$extension_id,
			$entity_id
		);
		if ( null === $descriptor ) {
			return new WP_Error( 'cb_core_data_exchange_unknown_entity', 'Unknown Data Exchange entity.' );
		}
		$supports = $descriptor['supports'] ?? [];
		if ( ! in_array( $operation_support, $supports, true ) || ! in_array( $format_support, $supports, true ) ) {
			return new WP_Error( 'cb_core_data_exchange_unsupported', 'Data Exchange entity does not support the requested operation or format.' );
		}
		$entity = Registry::resolve(
			Foundation::CONTRACT_OWNER,
			Foundation::CONTRACT_ID,
			Foundation::CONTRACT_VERSION,
			$descriptor['provider'],
			$descriptor['id']
		);
		if ( is_wp_error( $entity ) ) {
			return $entity;
		}
		if ( ! $entity instanceof EntityInterface ) {
			return new WP_Error( 'cb_core_data_exchange_contract', 'Resolved Data Exchange implementation does not satisfy the entity contract.' );
		}
		try {
			if ( ! $entity->is_available() ) {
				return new WP_Error( 'cb_core_data_exchange_unavailable', 'Data Exchange entity is not currently available.' );
			}
			$authorized = $for_import ? $entity->can_import( $context ) : $entity->can_export( $context );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return new WP_Error( 'cb_core_data_exchange_provider_failed', 'Data Exchange provider failed while checking availability or authorization.' );
		}
		if ( ! $authorized ) {
			return new WP_Error( 'cb_core_data_exchange_forbidden', 'Current actor is not authorized for this Data Exchange operation.' );
		}
		return [ $descriptor, $entity ];
	}

	private static function current_schema_version( EntityInterface $entity ): int|WP_Error {
		try {
			$version = $entity->schema_version();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return new WP_Error( 'cb_core_data_exchange_provider_failed', 'Data Exchange provider failed while resolving its schema version.' );
		}
		return $version > 0 ? $version : new WP_Error( 'cb_core_data_exchange_invalid_schema', 'Data Exchange entity returned an invalid schema version.' );
	}

	/** @return list<array<string,mixed>>|WP_Error */
	private static function export_records( EntityInterface $entity, array $context ): array|WP_Error {
		try {
			$records = $entity->export_records( $context );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return new WP_Error( 'cb_core_data_exchange_provider_failed', 'Data Exchange provider failed while exporting records.' );
		}
		if ( is_wp_error( $records ) ) {
			return self::bounded_provider_error( $records );
		}
		$out = [];
		$transport_bytes = 0;
		foreach ( $records as $record ) {
			if ( count( $out ) >= Foundation::MAX_RECORDS ) {
				return new WP_Error( 'cb_core_data_exchange_too_many_records', 'Data Exchange record count exceeds the transport limit.' );
			}
			if ( ! is_array( $record ) || [] === $record || array_is_list( $record ) || ! self::transport_safe( $record ) ) {
				return new WP_Error( 'cb_core_data_exchange_invalid_record', 'Data Exchange provider returned an invalid transport record.' );
			}
			$encoded = self::encode_json( $record );
			if ( is_wp_error( $encoded ) ) {
				return $encoded;
			}
			$transport_bytes += strlen( $encoded );
			if ( $transport_bytes > Foundation::MAX_INPUT_BYTES ) {
				return new WP_Error( 'cb_core_data_exchange_output_too_large', 'Data Exchange output exceeds the transport limit.' );
			}
			$out[] = $record;
		}
		return $out;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function decode_json_envelope( string $input ): array|WP_Error {
		$input = self::bounded_input( $input );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		try {
			$decoded = json_decode( $input, true, 32, JSON_THROW_ON_ERROR );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return new WP_Error( 'cb_core_data_exchange_invalid_json', 'Data Exchange JSON is malformed.' );
		}
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
			return new WP_Error( 'cb_core_data_exchange_invalid_envelope', 'Data Exchange JSON envelope must be an object.' );
		}
		$allowed = [ 'format', 'format_version', 'extension_id', 'entity', 'schema_version', 'exported_at', 'records' ];
		if ( [] !== array_diff( array_keys( $decoded ), $allowed ) || [] !== array_diff( $allowed, array_keys( $decoded ) ) ) {
			return new WP_Error( 'cb_core_data_exchange_invalid_envelope', 'Data Exchange JSON envelope has an unsupported shape.' );
		}
		if (
			Foundation::FORMAT_ID !== $decoded['format']
			|| Foundation::FORMAT_VERSION !== $decoded['format_version']
			|| ! is_string( $decoded['extension_id'] )
			|| ! is_string( $decoded['entity'] )
			|| ! is_int( $decoded['schema_version'] )
			|| $decoded['schema_version'] <= 0
			|| ! is_string( $decoded['exported_at'] )
			|| strlen( $decoded['exported_at'] ) > 64
			|| ! is_array( $decoded['records'] )
			|| ! array_is_list( $decoded['records'] )
			|| count( $decoded['records'] ) > Foundation::MAX_RECORDS
		) {
			return new WP_Error( 'cb_core_data_exchange_invalid_envelope', 'Data Exchange JSON envelope metadata is invalid.' );
		}
		return $decoded;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function decode_csv_envelope( string $input, array $context ): array|WP_Error {
		$input = self::bounded_input( $input );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$stream = fopen( 'php://temp', 'w+b' );
		if ( false === $stream || false === fwrite( $stream, $input ) ) {
			if ( is_resource( $stream ) ) {
				fclose( $stream );
			}
			return new WP_Error( 'cb_core_data_exchange_csv_failed', 'Could not open the Data Exchange CSV transport.' );
		}
		rewind( $stream );
		$header = fgetcsv( $stream, 0, ',', '"', '' );
		$meta   = fgetcsv( $stream, 0, ',', '"', '' );
		if ( ! is_array( $header ) || ! is_array( $meta ) || count( $header ) !== count( $meta ) ) {
			fclose( $stream );
			return new WP_Error( 'cb_core_data_exchange_invalid_csv', 'Data Exchange CSV header or metadata row is missing.' );
		}
		$reserved = [ 'cb_row_type', 'cb_format', 'cb_format_version', 'cb_extension_id', 'cb_entity', 'cb_schema_version' ];
		if ( count( $header ) < count( $reserved ) || array_slice( $header, 0, count( $reserved ) ) !== $reserved ) {
			fclose( $stream );
			return new WP_Error( 'cb_core_data_exchange_invalid_csv', 'Data Exchange CSV reserved columns are invalid.' );
		}
		if (
			'meta' !== ( $meta[0] ?? null )
			|| Foundation::FORMAT_ID !== ( $meta[1] ?? null )
			|| (string) Foundation::FORMAT_VERSION !== ( $meta[2] ?? null )
			|| ! isset( $meta[3], $meta[4], $meta[5] )
			|| 1 !== preg_match( '/^[1-9][0-9]*$/D', (string) $meta[5] )
		) {
			fclose( $stream );
			return new WP_Error( 'cb_core_data_exchange_invalid_csv', 'Data Exchange CSV metadata is invalid.' );
		}
		$extension_id   = (string) $meta[3];
		$entity_id      = (string) $meta[4];
		$schema_version = (int) $meta[5];
		foreach ( array_slice( $meta, count( $reserved ) ) as $value ) {
			if ( '' !== (string) $value ) {
				fclose( $stream );
				return new WP_Error( 'cb_core_data_exchange_invalid_csv', 'Data Exchange CSV metadata row must not contain entity data.' );
			}
		}

		$resolved = self::resolve_entity( $extension_id, $entity_id, Foundation::SUPPORT_IMPORT, Foundation::SUPPORT_CSV, $context, true );
		if ( is_wp_error( $resolved ) ) {
			fclose( $stream );
			return $resolved;
		}
		[ , $entity ] = $resolved;
		if ( ! $entity instanceof CsvEntityInterface ) {
			fclose( $stream );
			return new WP_Error( 'cb_core_data_exchange_csv_contract', 'Data Exchange entity declares CSV support without implementing the CSV contract.' );
		}
		try {
			$supported = $entity->supports_schema_version( $schema_version );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			fclose( $stream );
			return new WP_Error( 'cb_core_data_exchange_provider_failed', 'Data Exchange provider failed while checking the source schema version.' );
		}
		if ( ! $supported ) {
			fclose( $stream );
			return new WP_Error( 'cb_core_data_exchange_unsupported_schema', 'Data Exchange source schema version is not supported by this entity.' );
		}
		$columns = self::csv_columns( $entity, $schema_version );
		if ( is_wp_error( $columns ) ) {
			fclose( $stream );
			return $columns;
		}
		if ( array_slice( $header, count( $reserved ) ) !== $columns ) {
			fclose( $stream );
			return new WP_Error( 'cb_core_data_exchange_invalid_csv', 'Data Exchange CSV entity columns do not match the declared schema.' );
		}

		$records      = [];
		$record_bytes = 0;
		while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) {
			if ( [ null ] === $row ) {
				continue;
			}
			if ( count( $row ) !== count( $header ) || 'data' !== ( $row[0] ?? null ) ) {
				fclose( $stream );
				return new WP_Error( 'cb_core_data_exchange_invalid_csv', 'Data Exchange CSV contains an invalid data row.' );
			}
			foreach ( array_slice( $row, 1, count( $reserved ) - 1 ) as $reserved_value ) {
				if ( '' !== (string) $reserved_value ) {
					fclose( $stream );
					return new WP_Error( 'cb_core_data_exchange_invalid_csv', 'Data Exchange CSV data rows must not override metadata.' );
				}
			}
			if ( count( $records ) >= Foundation::MAX_RECORDS ) {
				fclose( $stream );
				return new WP_Error( 'cb_core_data_exchange_too_many_records', 'Data Exchange record count exceeds the transport limit.' );
			}
			$mapped = [];
			foreach ( $columns as $offset => $column ) {
				$mapped[ $column ] = self::restore_csv_cell( (string) $row[ count( $reserved ) + $offset ] );
			}
			try {
				$record = $entity->from_csv_row( $mapped, $schema_version );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
				fclose( $stream );
				return new WP_Error( 'cb_core_data_exchange_provider_failed', 'Data Exchange provider failed while decoding a CSV record.' );
			}
			if ( is_wp_error( $record ) ) {
				fclose( $stream );
				return self::bounded_provider_error( $record );
			}
			if ( ! is_array( $record ) || [] === $record || array_is_list( $record ) || ! self::transport_safe( $record ) ) {
				fclose( $stream );
				return new WP_Error( 'cb_core_data_exchange_csv_contract', 'Data Exchange CSV provider returned an invalid canonical record.' );
			}
			$encoded_record = self::encode_json( $record );
			if ( is_wp_error( $encoded_record ) ) {
				fclose( $stream );
				return $encoded_record;
			}
			$record_bytes += strlen( $encoded_record );
			if ( $record_bytes > Foundation::MAX_INPUT_BYTES ) {
				fclose( $stream );
				return new WP_Error( 'cb_core_data_exchange_output_too_large', 'Data Exchange provider output exceeds the transport limit.' );
			}
			$records[] = $record;
		}
		fclose( $stream );

		return [
			'format'         => Foundation::FORMAT_ID,
			'format_version' => Foundation::FORMAT_VERSION,
			'extension_id'   => $extension_id,
			'entity'         => $entity_id,
			'schema_version' => $schema_version,
			'exported_at'    => '',
			'records'        => $records,
		];
	}

	private static function bounded_input( string $input ): string|WP_Error {
		if ( strlen( $input ) > Foundation::MAX_INPUT_BYTES ) {
			return new WP_Error( 'cb_core_data_exchange_input_too_large', 'Data Exchange input exceeds the transport limit.' );
		}
		if ( str_contains( $input, "\0" ) ) {
			return new WP_Error( 'cb_core_data_exchange_invalid_input', 'Data Exchange input contains invalid null bytes.' );
		}
		if ( str_starts_with( $input, "\xEF\xBB\xBF" ) ) {
			$input = substr( $input, 3 );
		}
		return $input;
	}

	/** @return list<string>|WP_Error */
	private static function csv_columns( CsvEntityInterface $entity, int $schema_version ): array|WP_Error {
		try {
			$columns = $entity->csv_columns( $schema_version );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return new WP_Error( 'cb_core_data_exchange_provider_failed', 'Data Exchange provider failed while declaring CSV columns.' );
		}
		if ( ! array_is_list( $columns ) || [] === $columns || count( $columns ) > Foundation::MAX_CSV_COLUMNS ) {
			return new WP_Error( 'cb_core_data_exchange_csv_contract', 'Data Exchange entity returned invalid CSV columns.' );
		}
		$seen = [];
		foreach ( $columns as $column ) {
			if (
				! is_string( $column )
				|| 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $column )
				|| str_starts_with( $column, 'cb_' )
				|| isset( $seen[ $column ] )
			) {
				return new WP_Error( 'cb_core_data_exchange_csv_contract', 'Data Exchange entity returned invalid or duplicate CSV columns.' );
			}
			$seen[ $column ] = true;
		}
		return $columns;
	}

	/** @return array<string,string>|WP_Error */
	private static function normalize_csv_export_row( mixed $row, array $columns ): array|WP_Error {
		if ( ! is_array( $row ) || array_keys( $row ) !== $columns ) {
			return new WP_Error( 'cb_core_data_exchange_csv_contract', 'Data Exchange CSV row does not match the declared columns.' );
		}
		$out = [];
		foreach ( $columns as $column ) {
			$value = $row[ $column ];
			if ( null === $value ) {
				$out[ $column ] = '';
				continue;
			}
			if ( is_bool( $value ) ) {
				$out[ $column ] = $value ? '1' : '0';
				continue;
			}
			if ( ! is_scalar( $value ) || ( is_float( $value ) && ! is_finite( $value ) ) ) {
				return new WP_Error( 'cb_core_data_exchange_csv_contract', 'Data Exchange CSV cells must be scalar or null.' );
			}
			$out[ $column ] = (string) $value;
		}
		return $out;
	}

	private static function protect_csv_cell( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( "'" === $value[0] || 1 === preg_match( '/^[ \t\r\n]*[=+\-@]/D', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	private static function restore_csv_cell( string $value ): string {
		if ( str_starts_with( $value, "''" ) ) {
			return substr( $value, 1 );
		}
		if ( str_starts_with( $value, "'" ) && 1 === preg_match( '/^[ \t\r\n]*[=+\-@]/D', substr( $value, 1 ) ) ) {
			return substr( $value, 1 );
		}
		return $value;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function normalize_plan( mixed $plan, string $mode ): array|WP_Error {
		if ( ! is_array( $plan ) || array_is_list( $plan ) ) {
			return new WP_Error( 'cb_core_data_exchange_plan_contract', 'Data Exchange provider returned an invalid import plan.' );
		}
		$allowed = [ 'operation', 'reference', 'payload', 'warnings' ];
		if ( [] !== array_diff( array_keys( $plan ), $allowed ) || ! isset( $plan['operation'], $plan['reference'], $plan['payload'] ) ) {
			return new WP_Error( 'cb_core_data_exchange_plan_contract', 'Data Exchange provider returned an unsupported import plan shape.' );
		}
		$operation = is_string( $plan['operation'] ) ? $plan['operation'] : '';
		$reference = is_string( $plan['reference'] ) ? self::reference( $plan['reference'] ) : null;
		$payload   = $plan['payload'];
		$warnings  = $plan['warnings'] ?? [];
		if ( $operation !== trim( $operation ) || ! Foundation::is_operation( $operation ) || null === $reference || ! is_array( $payload ) || ! self::transport_safe( $payload ) ) {
			return new WP_Error( 'cb_core_data_exchange_plan_contract', 'Data Exchange provider returned invalid plan values.' );
		}
		if ( Foundation::MODE_CREATE_ONLY === $mode && Foundation::OP_UPDATE === $operation ) {
			return new WP_Error( 'cb_core_data_exchange_plan_contract', 'Data Exchange provider planned an update in create-only mode.' );
		}
		if ( Foundation::MODE_UPDATE_EXISTING === $mode && Foundation::OP_CREATE === $operation ) {
			return new WP_Error( 'cb_core_data_exchange_plan_contract', 'Data Exchange provider planned a create in update-existing mode.' );
		}
		if ( ! is_array( $warnings ) || ! array_is_list( $warnings ) || count( $warnings ) > self::MAX_WARNING_COUNT ) {
			return new WP_Error( 'cb_core_data_exchange_plan_contract', 'Data Exchange provider returned invalid plan warnings.' );
		}
		$clean_warnings = [];
		foreach ( $warnings as $warning ) {
			if ( ! is_string( $warning ) || strlen( $warning ) > self::MAX_WARNING_LEN ) {
				return new WP_Error( 'cb_core_data_exchange_plan_contract', 'Data Exchange provider returned invalid plan warnings.' );
			}
			$clean_warnings[] = $warning;
		}
		return [
			'operation' => $operation,
			'reference' => $reference,
			'payload'   => $payload,
			'warnings'  => $clean_warnings,
		];
	}

	private static function reference( string $reference ): ?string {
		return $reference === trim( $reference )
			&& '' !== $reference
			&& strlen( $reference ) <= self::MAX_REFERENCE_LEN
			&& ! str_contains( $reference, "\0" )
			? $reference
			: null;
	}

	private static function bounded_provider_error( WP_Error $error ): WP_Error {
		$code = (string) $error->get_error_code();
		if (
			$code !== trim( $code )
			|| strlen( $code ) > self::MAX_ERROR_CODE_LEN
			|| 1 !== preg_match( '/^[a-z][a-z0-9_.:-]*$/D', $code )
		) {
			$code = 'cb_core_data_exchange_provider_error';
		}

		$message = sanitize_text_field( (string) $error->get_error_message() );
		if ( '' === $message ) {
			$message = 'Data Exchange provider returned an error.';
		}
		if ( strlen( $message ) > self::MAX_ERROR_MESSAGE_LEN ) {
			$message = function_exists( 'mb_strcut' )
				? mb_strcut( $message, 0, self::MAX_ERROR_MESSAGE_LEN, 'UTF-8' )
				: substr( $message, 0, self::MAX_ERROR_MESSAGE_LEN );
		}
		return new WP_Error( $code, $message );
	}

	private static function transport_safe( mixed $value, int $depth = 0 ): bool {
		if ( $depth > self::MAX_DEPTH ) {
			return false;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return ! str_contains( $value, "\0" );
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
			if ( is_string( $key ) && ( '' === $key || strlen( $key ) > 191 || str_contains( $key, "\0" ) ) ) {
				return false;
			}
			if ( ! self::transport_safe( $child, $depth + 1 ) ) {
				return false;
			}
		}
		return true;
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( [ self::class, 'canonicalize' ], $value );
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::canonicalize( $child );
		}
		return $value;
	}

	private static function encode_json( mixed $value ): string|WP_Error {
		try {
			return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return new WP_Error( 'cb_core_data_exchange_json_failed', 'Could not encode Data Exchange JSON.' );
		}
	}
}
