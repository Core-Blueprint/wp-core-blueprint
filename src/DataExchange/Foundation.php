<?php
declare(strict_types=1);
/**
 * Core Blueprint Data Exchange Foundation contract definition.
 *
 * Base owns transport, bounded parsing, versioned envelopes, mapping primitives
 * and preview/apply orchestration. Extensions keep ownership of entity schemas,
 * authorization, portable identity resolution and canonical domain mutations.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\DataExchange;

defined( 'ABSPATH' ) || exit;

final class Foundation {

	public const CONTRACT_OWNER   = 'core-blueprint';
	public const CONTRACT_ID      = 'data-exchange.entity';
	public const CONTRACT_VERSION = '1';

	public const FORMAT_ID      = 'core-blueprint-data-exchange';
	public const FORMAT_VERSION = 1;

	public const SUPPORT_EXPORT  = 'export';
	public const SUPPORT_IMPORT  = 'import';
	public const SUPPORT_MAPPING = 'mapping';
	public const SUPPORT_JSON    = 'format.json';
	public const SUPPORT_CSV     = 'format.csv';

	public const DIRECTION_IMPORT = 'import';
	public const DIRECTION_EXPORT = 'export';

	public const MODE_CREATE_ONLY     = 'create_only';
	public const MODE_UPDATE_EXISTING = 'update_existing';
	public const MODE_CREATE_UPDATE   = 'create_update';

	public const OP_CREATE = 'create';
	public const OP_UPDATE = 'update';
	public const OP_SKIP   = 'skip';

	public const MAP_DIRECT   = 'direct';
	public const MAP_CONSTANT = 'constant';
	public const MAP_IGNORE   = 'ignore';

	public const MAX_INPUT_BYTES = 10 * 1024 * 1024;
	public const MAX_RECORDS     = 5000;
	public const MAX_CSV_COLUMNS = 256;

	/** @return array{owner:string,id:string,version:string,label:string,description:string,interface:string} */
	public static function contract_definition(): array {
		return [
			'owner'       => self::CONTRACT_OWNER,
			'id'          => self::CONTRACT_ID,
			'version'     => self::CONTRACT_VERSION,
			'label'       => __( 'Data Exchange entity', 'core-blueprint' ),
			'description' => __( 'Provides versioned import and export of extension-owned data through the Core Blueprint Data Exchange Foundation.', 'core-blueprint' ),
			'interface'   => EntityInterface::class,
		];
	}

	public static function is_direction( string $direction ): bool {
		return in_array( $direction, [ self::DIRECTION_IMPORT, self::DIRECTION_EXPORT ], true );
	}

	public static function is_import_mode( string $mode ): bool {
		return in_array( $mode, [ self::MODE_CREATE_ONLY, self::MODE_UPDATE_EXISTING, self::MODE_CREATE_UPDATE ], true );
	}

	public static function is_operation( string $operation ): bool {
		return in_array( $operation, [ self::OP_CREATE, self::OP_UPDATE, self::OP_SKIP ], true );
	}

	public static function is_mapping_transform( string $transform ): bool {
		return in_array( $transform, [ self::MAP_DIRECT, self::MAP_CONSTANT, self::MAP_IGNORE ], true );
	}
}
