<?php
declare(strict_types=1);

final class CB_Base_Performance_Baseline_Contract_Test extends WP_UnitTestCase {

	public function test_wordpress_controls_are_warmed_before_measurement(): void {
		$runner = file_get_contents( CB_CORE_DIR . 'tests/bin/run-performance-baseline.sh' );
		self::assertIsString( $runner );

		$warmup   = strpos( $runner, 'echo "[F3A] warming WordPress-only control state"' );
		$frontend = strpos( $runner, 'profile_as frontend control_frontend 0 0' );
		$admin    = strpos( $runner, 'profile_as admin control_admin 0 1 0 1' );

		self::assertIsInt( $warmup );
		self::assertIsInt( $frontend );
		self::assertIsInt( $admin );
		self::assertLessThan( $frontend, $warmup );
		self::assertLessThan( $admin, $warmup );
		self::assertStringContainsString( 'php "$REQUEST" frontend >/dev/null', $runner );
		self::assertStringContainsString( 'php "$REQUEST" admin >/dev/null', $runner );
	}

	public function test_reports_profile_is_explicit_opt_in_after_default_state_scenarios(): void {
		$runner = file_get_contents( CB_CORE_DIR . 'tests/bin/run-performance-baseline.sh' );
		self::assertIsString( $runner );

		self::assertStringContainsString( 'php "$REQUEST" enable_reports', $runner );
		self::assertStringContainsString( 'profile_as reports reports 1 1 0 0 0 reports', $runner );
		self::assertSame( 14, preg_match_all( '/^profile_as /m', $runner ) );

		$safeguards = strpos( $runner, 'profile_as safeguards safeguards 1 1' );
		$enable     = strpos( $runner, 'php "$REQUEST" enable_reports' );
		$reports    = strpos( $runner, 'profile_as reports reports 1 1 0 0 0 reports' );
		$disable    = strpos( $runner, 'php "$REQUEST" disable_modules' );

		self::assertIsInt( $safeguards );
		self::assertIsInt( $enable );
		self::assertIsInt( $reports );
		self::assertIsInt( $disable );
		self::assertLessThan( $enable, $safeguards );
		self::assertLessThan( $reports, $enable );
		self::assertLessThan( $disable, $reports );
	}

	public function test_reports_opt_in_uses_canonical_state_authority(): void {
		$request = file_get_contents( CB_CORE_DIR . 'tests/performance/request.php' );
		self::assertIsString( $request );

		self::assertStringContainsString( "'enable_reports'", $request );
		self::assertStringContainsString(
			"false === \\CoreBlueprint\\Core\\Reports\\State::is_enabled()",
			$request
		);
		self::assertStringContainsString(
			"\\CoreBlueprint\\Core\\Reports\\State::set_enabled(true, 'performance-harness')",
			$request
		);
		self::assertStringContainsString(
			"true === \\CoreBlueprint\\Core\\Reports\\State::is_enabled()",
			$request
		);
	}

	public function test_disabled_routing_skips_redundant_suspension_delete(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/Routing/Runtime.php' );
		self::assertIsString( $source );

		self::assertStringContainsString(
			"if ( false !== get_option( self::RUNTIME_SUSPENDED_OPTION, false ) ) {",
			$source
		);
		self::assertStringContainsString(
			"delete_option( self::RUNTIME_SUSPENDED_OPTION );",
			$source
		);
		self::assertStringNotContainsString(
			'self::$prepared_ready = false;' . "\n\t\t\t" . 'delete_option( self::RUNTIME_SUSPENDED_OPTION );',
			$source
		);
	}

	public function test_profile_records_identify_opt_in_modules(): void {
		$runner = file_get_contents( CB_CORE_DIR . 'tests/bin/run-performance-baseline.sh' );
		self::assertIsString( $runner );

		self::assertStringContainsString( 'local opt_in_modules="${8:-}"', $runner );
		self::assertStringContainsString( '"opt_in_modules" => $optInModules', $runner );
	}
}
