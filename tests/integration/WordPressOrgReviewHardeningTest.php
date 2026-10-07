<?php
declare(strict_types=1);

use CoreBlueprint\Core\RequestContext;

final class CB_Base_WordPress_Org_Review_Hardening_Test extends WP_UnitTestCase {

	public function test_request_context_preserves_safe_relative_request_uri(): void {
		$previous = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/blog/p2?lang=nl&view=grid';

		try {
			self::assertSame( '/blog/p2?lang=nl&view=grid', RequestContext::request_uri() );
		} finally {
			if ( null === $previous ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $previous;
			}
		}
	}

	public function test_reviewed_request_consumers_use_the_central_sanitized_boundary(): void {
		$request_context = file_get_contents( CB_CORE_DIR . 'src/RequestContext.php' );
		self::assertIsString( $request_context );
		self::assertStringContainsString( 'sanitize_url( wp_unslash(', $request_context );

		foreach ( [
			'src/Routing/Runtime.php',
			'src/Security/LoginShield.php',
			'src/Security/Modules/Fingerprint.php',
		] as $relative ) {
			$content = file_get_contents( CB_CORE_DIR . $relative );
			self::assertIsString( $content );
			self::assertStringContainsString( 'RequestContext::request_uri()', $content );
			self::assertStringNotContainsString(
				"wp_unslash( \$_SERVER['REQUEST_URI'] )",
				$content,
				'Reviewed request consumers must not read unsanitized REQUEST_URI directly: ' . $relative
			);
		}
	}

	public function test_hud_palette_uses_wordpress_inline_style_api(): void {
		$assets = file_get_contents( CB_CORE_DIR . 'src/HUD/Assets.php' );
		$hud    = file_get_contents( CB_CORE_DIR . 'src/HUD/HUD.php' );
		self::assertIsString( $assets );
		self::assertIsString( $hud );

		self::assertStringContainsString( 'wp_add_inline_style( self::STYLE_HANDLE, $palette_css )', $assets );
		self::assertStringNotContainsString( 'cb-hud-brand-palette-', $hud );
	}

	public function test_svg_sanitizer_avoids_deprecated_entity_loader_api(): void {
		$sanitizer = file_get_contents( CB_CORE_DIR . 'src/MediaFormats/lib/svg-sanitizer/src/Sanitizer.php' );
		self::assertIsString( $sanitizer );

		self::assertStringContainsString( 'LIBXML_NONET', $sanitizer );
		self::assertStringNotContainsString( 'libxml_disable_entity_loader', $sanitizer );
	}

	public function test_first_party_gettext_calls_never_use_default_domain(): void {
		$roots = [
			CB_CORE_DIR . 'core-blueprint.php',
			CB_CORE_DIR . 'includes',
			CB_CORE_DIR . 'src',
			CB_CORE_DIR . 'templates',
		];
		$pattern = '/\\b(?:__|_e|_x|_n|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\s*\\((?:(?!;).){0,800}?[\'"]default[\'"]/s';

		foreach ( $roots as $root ) {
			$files = [];
			if ( is_file( $root ) ) {
				$files[] = $root;
			} elseif ( is_dir( $root ) ) {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
				);
				foreach ( $iterator as $file ) {
					if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
						continue;
					}
					$path = $file->getPathname();
					if (
						str_contains( $path, '/src/PDF/lib/' )
						|| str_contains( $path, '/src/MediaFormats/lib/svg-sanitizer/' )
					) {
						continue;
					}
					$files[] = $path;
				}
			}

			foreach ( $files as $file ) {
				$source = file_get_contents( $file );
				self::assertIsString( $source );
				self::assertSame(
					0,
					preg_match( $pattern, $source ),
					'WordPress.org gettext domain regressed to "default" in ' . $file
				);
			}
		}
	}

	public function test_review_flagged_output_buffers_are_closed_in_template_scope(): void {
		foreach ( [
			'templates/login-shield.php',
			'templates/language.php',
		] as $relative ) {
			$content = file_get_contents( CB_CORE_DIR . $relative );
			self::assertIsString( $content );
			self::assertSame(
				substr_count( $content, 'ob_start(' ),
				substr_count( $content, 'ob_get_clean(' ),
				'Every review-flagged template output buffer must close in the same template flow: ' . $relative
			);
		}
	}

	public function test_content_model_setting_contract_exposes_sanitize_callback(): void {
		$field_types = file_get_contents( CB_CORE_DIR . 'src/ContentModels/FieldTypes.php' );
		self::assertIsString( $field_types );
		self::assertStringContainsString( "'sanitize_callback'", $field_types );
		self::assertStringContainsString( 'sanitize_value', $field_types );
	}
}