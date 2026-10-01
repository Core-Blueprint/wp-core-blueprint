<?php
declare(strict_types=1);

final class CB_Base_WordPress_Org_Submission_Contract_Test extends WP_UnitTestCase {

	public function test_directory_metadata_matches_runtime_version(): void {
		$readme = file_get_contents( CB_CORE_DIR . 'readme.txt' );
		$plugin = file_get_contents( CB_CORE_FILE );
		self::assertIsString( $readme );
		self::assertIsString( $plugin );
		self::assertStringContainsString( 'Stable tag: ' . CB_CORE_VERSION, $readme );
		self::assertMatchesRegularExpression(
			'/^\\s*\\*\\s*Version:\\s*' . preg_quote( CB_CORE_VERSION, '/' ) . '\\s*$/m',
			$plugin
		);
		self::assertStringContainsString( 'Requires at least: 7.0', $readme );
		self::assertStringContainsString( 'Tested up to: 7.1', $readme );
		self::assertStringContainsString( 'Requires PHP: 8.4', $readme );
		self::assertStringContainsString( 'License: GPLv2 or later', $readme );
	}

	public function test_brevo_external_service_is_disclosed(): void {
		$readme = file_get_contents( CB_CORE_DIR . 'readme.txt' );
		$brevo  = file_get_contents( CB_CORE_DIR . 'src/Mail/Transport/BrevoTransport.php' );
		self::assertIsString( $readme );
		self::assertIsString( $brevo );
		self::assertStringContainsString( '== External services ==', $readme );
		self::assertStringContainsString( 'https://api.brevo.com', $brevo );
		self::assertStringContainsString( 'https://www.brevo.com/legal/termsofuse/', $readme );
		self::assertStringContainsString( 'https://www.brevo.com/legal/privacypolicy/', $readme );
		self::assertStringContainsString( 'Brevo is contacted only when all of the following are true', $readme );
	}

	public function test_release_builder_requires_directory_readme(): void {
		$builder = file_get_contents( CB_CORE_DIR . 'tools/build-release' );
		self::assertIsString( $builder );
		self::assertStringContainsString( '"readme.txt"', $builder );
	}

	public function test_base_runtime_does_not_inject_third_party_plugin_updates(): void {
		$roots = [
			CB_CORE_DIR . 'core-blueprint.php',
			CB_CORE_DIR . 'includes',
			CB_CORE_DIR . 'src',
		];
		$forbidden = [
			'pre_set_site_transient_update_plugins',
			'plugins_api',
			'Plugin_Upgrader',
			'Theme_Upgrader',
		];

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
				foreach ( $forbidden as $token ) {
					self::assertStringNotContainsString(
						$token,
						$content,
						'WordPress.org executable software delivery boundary regressed in ' . $file
					);
				}
			}
		}
	}
}
