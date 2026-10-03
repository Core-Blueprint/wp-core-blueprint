<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets;

defined( 'ABSPATH' ) || exit;

final class Paths {
	public static function base_dir(): string {
		$default = trailingslashit( WP_CONTENT_DIR ) . 'cb-snippets';
		return untrailingslashit( (string) apply_filters( 'cb_core_snippets_storage_dir', $default ) );
	}

	public static function code_dir(): string {
		return self::base_dir() . '/code';
	}

	public static function registry(): string {
		return self::base_dir() . '/registry.php';
	}

	public static function runtime_index(): string {
		return self::base_dir() . '/runtime-index.php';
	}

	public static function lock_file(): string {
		return self::base_dir() . '/.lock';
	}

	public static function code_file( string $id ): string {
		$file = preg_replace( '/[^a-zA-Z0-9_-]/', '', $id ) ?: 'invalid';
		return self::code_dir() . '/' . $file . '.php';
	}

	public static function ensure(): bool {
		if ( ! is_dir( self::code_dir() ) && ! wp_mkdir_p( self::code_dir() ) ) {
			return false;
		}

		$guards = [
			self::base_dir() . '/index.php' => "<?php\ndefined( 'ABSPATH' ) || exit;\n",
			self::base_dir() . '/.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			self::base_dir() . '/web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></system.webServer></configuration>\n",
			self::code_dir() . '/index.php' => "<?php\ndefined( 'ABSPATH' ) || exit;\n",
		];

		foreach ( $guards as $path => $contents ) {
			if ( ! is_file( $path ) && false === @file_put_contents( $path, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return false;
			}
		}

		return true;
	}
}
