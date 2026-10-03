<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets;

use CoreBlueprint\Core\Snippets\Conditions\Engine;

defined( 'ABSPATH' ) || exit;

final class Runtime {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted || SafeMode::is_active() || ! State::is_enabled() ) {
			return;
		}
		self::$booted = true;
		ErrorGuard::init();

		foreach ( IndexBuilder::load_runtime_manifest() as $meta ) {
			if ( ! is_array( $meta ) || empty( $meta['id'] ) ) {
				continue;
			}
			self::register( $meta );
		}
	}

	private static function register( array $meta ): void {
		$type     = (string) ( $meta['type'] ?? '' );
		$location = (string) ( $meta['location'] ?? '' );
		$priority = max( 1, min( 999, (int) ( $meta['priority'] ?? 10 ) ) );

		if ( 'php' === $type ) {
			if ( 'shortcode' === $location ) {
				self::register_shortcode( $meta, true );
				return;
			}
			if ( Schema::valid_location( 'php', $location ) ) {
				add_action( $location, static function () use ( $meta ): void {
					self::execute_php( $meta );
				}, $priority );
			}
			return;
		}

		if ( 'css' === $type ) {
			$hooks = 'both' === $location ? [ 'wp_head', 'admin_head' ] : [ 'admin' === $location ? 'admin_head' : 'wp_head' ];
			foreach ( $hooks as $hook ) {
				add_action( $hook, static function () use ( $meta ): void {
					self::render_asset( $meta, 'style' );
				}, $priority );
			}
			return;
		}

		if ( 'js' === $type && Schema::valid_location( 'js', $location ) ) {
			add_action( $location, static function () use ( $meta ): void {
				self::render_asset( $meta, 'script' );
			}, $priority );
			return;
		}

		if ( 'html' === $type ) {
			if ( 'shortcode' === $location ) {
				self::register_shortcode( $meta, false );
			} elseif ( Schema::valid_location( 'html', $location ) ) {
				add_action( $location, static function () use ( $meta ): void {
					self::render_html( $meta );
				}, $priority );
			}
		}
	}

	private static function register_shortcode( array $meta, bool $php ): void {
		$shortcode = sanitize_key( (string) ( $meta['shortcode'] ?? '' ) );
		if ( '' === $shortcode ) {
			return;
		}

		add_shortcode( $shortcode, static function ( $atts = [], $content = null, $tag = '' ) use ( $meta, $php ): string {
			if ( ! self::conditions_match( $meta ) ) {
				return '';
			}
			if ( $php ) {
				return self::execute_php( $meta, (array) $atts, is_string( $content ) ? $content : null, (string) $tag, true );
			}
			$code = self::load_non_php_code( $meta );
			return '' !== $code ? do_shortcode( $code ) : '';
		} );
	}

	private static function execute_php( array $meta, array $atts = [], ?string $content = null, string $tag = '', bool $capture = false ): string {
		if ( ! self::conditions_match( $meta ) ) {
			return '';
		}
		$id   = (string) $meta['id'];
		$file = Paths::code_file( $id );
		if ( ! self::php_integrity_ok( $meta, $file ) ) {
			return '';
		}

		$cb_snippet = $meta; // Deliberately available to snippet authors.
		$output = '';
		if ( $capture ) {
			ob_start();
		}

		ErrorGuard::begin( $id );
		try {
			include $file;
		} catch ( \Throwable $e ) {
			Repository::disable_for_runtime_error( $id, [
				'message' => get_class( $e ) . ': ' . $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
			] );
		} finally {
			ErrorGuard::end();
			if ( $capture ) {
				$output = (string) ob_get_clean();
			}
		}
		return $output;
	}

	private static function render_asset( array $meta, string $element ): void {
		if ( ! self::conditions_match( $meta ) ) {
			return;
		}
		$code = self::load_non_php_code( $meta );
		if ( '' === $code ) {
			return;
		}
		$id = esc_attr( 'cb-snippet-' . (string) $meta['id'] );
		if ( 'script' === $element ) {
			$code = preg_replace( '#</script#i', '<\\/script', $code ) ?? $code;
			echo '<script id="' . $id . '">' . $code . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted operator-authored JavaScript.
			return;
		}
		$code = preg_replace( '#</style#i', '<\\/style', $code ) ?? $code;
		echo '<style id="' . $id . '">' . $code . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted operator-authored CSS.
	}

	private static function render_html( array $meta ): void {
		if ( ! self::conditions_match( $meta ) ) {
			return;
		}
		$code = self::load_non_php_code( $meta );
		if ( '' !== $code ) {
			echo do_shortcode( $code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted operator-authored HTML.
		}
	}

	private static function load_non_php_code( array $meta ): string {
		$id       = (string) ( $meta['id'] ?? '' );
		$type     = (string) ( $meta['type'] ?? '' );
		$path     = Paths::code_file( $id );
		$code     = CodeFile::read( $type, $path );
		$expected = (string) ( $meta['code_hash'] ?? '' );
		if ( is_string( $code ) && '' !== $expected && hash_equals( $expected, hash( 'sha256', $code ) ) ) {
			return $code;
		}

		self::disable_integrity_failure( $id, $path );
		return '';
	}

	private static function php_integrity_ok( array $meta, string $path ): bool {
		$id       = (string) ( $meta['id'] ?? '' );
		$code     = CodeFile::read( 'php', $path );
		$expected = (string) ( $meta['code_hash'] ?? '' );
		if ( is_string( $code ) && '' !== $expected && hash_equals( $expected, hash( 'sha256', $code ) ) ) {
			return true;
		}

		self::disable_integrity_failure( $id, $path );
		return false;
	}

	private static function disable_integrity_failure( string $id, string $path ): void {
		Repository::disable_for_runtime_error( $id, [
			'message' => 'Managed snippet file failed its runtime integrity check.',
			'file'    => $path,
			'line'    => 0,
		] );
	}

	private static function conditions_match( array $meta ): bool {
		$conditions = is_array( $meta['conditions'] ?? null ) ? $meta['conditions'] : [];
		return Engine::matches( $conditions );
	}
}
