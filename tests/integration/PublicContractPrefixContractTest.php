<?php
declare(strict_types=1);

/**
 * Regression guard for the pre-v1 public hook prefix cutover.
 *
 * Internal storage identifiers, nonce actions and WordPress AJAX action names
 * may still use cb_core_* where they are not public extension contracts. This
 * guard therefore inspects only WordPress hook API calls in Base-owned runtime
 * PHP and rejects the legacy public hook prefixes.
 */
final class CB_Base_Public_Contract_Prefix_Contract_Test extends WP_UnitTestCase {

	public function test_runtime_hook_contracts_do_not_use_legacy_cb_prefixes(): void {
		$roots = [
			CB_CORE_DIR . 'core-blueprint.php',
			CB_CORE_DIR . 'includes',
			CB_CORE_DIR . 'src',
			CB_CORE_DIR . 'templates',
		];

		$pattern = '/\b(?:add_action|add_filter|do_action|do_action_ref_array|apply_filters|has_action|has_filter|remove_action|remove_filter)\s*\(\s*([\'"])(?:cb_core_|cb_(?:admin_theme|hud_|console_|maintenance_report_|permissions_))/';

		$violations = [];

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
				$content = file_get_contents( $file );
				self::assertIsString( $content );

				if ( preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) < 1 ) {
					continue;
				}

				foreach ( $matches[0] as [ $match, $offset ] ) {
					$line = substr_count( substr( $content, 0, $offset ), "\n" ) + 1;
					$violations[] = str_replace( CB_CORE_DIR, '', $file ) . ':' . $line . ' ' . trim( $match );
				}
			}
		}

		self::assertSame(
			[],
			$violations,
			"Legacy public Core Blueprint hook prefixes returned:\n" . implode( "\n", $violations )
		);
	}
}
