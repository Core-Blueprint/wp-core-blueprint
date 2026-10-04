<?php
declare(strict_types=1);

use CoreBlueprint\Core\Support\ThrowableBoundary;

final class CB_Base_Exception_Boundary_Contract_Test extends WP_UnitTestCase {

	public function test_throwable_boundary_never_projects_raw_exception_message(): void {
		$secret = 'api_key=super-secret-value';
		$error  = new RuntimeException( 'Connection failed with ' . $secret );

		$context = ThrowableBoundary::context( $error, 'provider_failure' );

		self::assertSame( 'provider_failure', $context['reason'] );
		self::assertSame( RuntimeException::class, $context['exception_class'] );
		self::assertArrayNotHasKey( 'message', $context );
		self::assertStringNotContainsString(
			$secret,
			(string) wp_json_encode( $context )
		);
	}

	public function test_diagnostic_exposes_only_exception_identity(): void {
		$secret = 'token=another-secret-value';
		$error  = new LogicException( 'Provider failed with ' . $secret );

		$diagnostic = ThrowableBoundary::diagnostic( $error );

		self::assertSame( LogicException::class, $diagnostic );
		self::assertStringNotContainsString( $secret, $diagnostic );
		self::assertStringNotContainsString( $error->getMessage(), $diagnostic );
	}

	public function test_foundation_exception_boundaries_do_not_forward_raw_messages(): void {
		$files = [
			'src/Modules/ActivationRegistry.php',
			'src/Modules/Status.php',
			'src/Security/ModuleRegistry.php',
			'src/Interoperability/Registry.php',
			'src/Log/Retention.php',
			'src/SettingsMigrator.php',
			'src/Migration/Recovery.php',
		];

		foreach ( $files as $relative ) {
			$source = (string) file_get_contents( CB_CORE_DIR . $relative );

			self::assertStringNotContainsString(
				'getMessage()',
				$source,
				$relative . ' must not project raw Throwable messages across the Base foundation boundary.'
			);

			self::assertStringContainsString(
				'ThrowableBoundary',
				$source,
				$relative . ' must use the canonical safe Throwable projection.'
			);
		}
	}

	public function test_migration_recovery_persists_stable_failure_code_only(): void {
		$source = (string) file_get_contents( CB_CORE_DIR . 'src/Migration/Recovery.php' );

		self::assertStringContainsString(
			"\$state['error']  = 'reconcile_failed';",
			$source
		);

		self::assertStringNotContainsString(
			"\$state['error'] = sanitize_text_field( \$e->getMessage() );",
			$source
		);
	}
}
