<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

final class Identifier {
	private const PROVIDER_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)+$/';
	private const DESIGN_TYPE_PATTERN = self::PROVIDER_PATTERN;
	private const CAPABILITY_PATTERN = '/^[a-z][a-z0-9_]*(?:-[a-z0-9_]+)*(?:\.[a-z][a-z0-9_]*(?:-[a-z0-9_]+)*)*$/';
	private const NODE_TYPE_PATTERN = '/^[a-z][a-z0-9_]*(?:(?:-|\.)[a-z0-9_]+)*$/';

	public static function provider( string $id ): bool {
		return 1 === preg_match( self::PROVIDER_PATTERN, $id );
	}

	public static function design_type( string $id ): bool {
		return 1 === preg_match( self::DESIGN_TYPE_PATTERN, $id );
	}

	public static function capability( string $id ): bool {
		return 1 === preg_match( self::CAPABILITY_PATTERN, $id );
	}

	public static function node_type( string $id ): bool {
		return 1 === preg_match( self::NODE_TYPE_PATTERN, $id );
	}
}
