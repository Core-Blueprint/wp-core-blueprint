<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets;

defined( 'ABSPATH' ) || exit;

final class ErrorGuard {
	private static ?string $active_snippet = null;
	private static bool $shutdown_registered = false;

	public static function init(): void {
		if ( self::$shutdown_registered ) {
			return;
		}
		self::$shutdown_registered = true;
		register_shutdown_function( [ __CLASS__, 'on_shutdown' ] );
	}

	public static function begin( string $id ): void {
		self::$active_snippet = $id;
	}

	public static function end(): void {
		self::$active_snippet = null;
	}

	public static function on_shutdown(): void {
		if ( null === self::$active_snippet ) {
			return;
		}
		$error = error_get_last();
		if ( ! is_array( $error ) ) {
			return;
		}
		$fatal = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];
		if ( ! in_array( (int) ( $error['type'] ?? 0 ), $fatal, true ) ) {
			return;
		}
		Repository::disable_for_runtime_error( self::$active_snippet, $error );
	}
}
