<?php
declare(strict_types=1);

use CoreBlueprint\Core\OptionPolicy;

final class CB_Base_Option_Policy_Contract_Test extends WP_UnitTestCase {

	private const OPTION = 'cb_core_db_health_checked_at';
	private const VERSION_OPTION = 'cb_core_option_policy_version';

	private mixed $original_option = null;
	private mixed $original_version = null;
	private bool $option_existed = false;
	private bool $version_existed = false;
	private bool $option_autoloaded = false;
	private bool $version_autoloaded = false;

	public function set_up(): void {
		parent::set_up();

		$sentinel = new stdClass();
		$this->original_option = get_option( self::OPTION, $sentinel );
		$this->option_existed = $this->original_option !== $sentinel;
		$this->original_version = get_option( self::VERSION_OPTION, $sentinel );
		$this->version_existed = $this->original_version !== $sentinel;

		$alloptions = wp_load_alloptions();
		$this->option_autoloaded = array_key_exists( self::OPTION, $alloptions );
		$this->version_autoloaded = array_key_exists( self::VERSION_OPTION, $alloptions );

		delete_option( self::OPTION );
		delete_option( self::VERSION_OPTION );
		self::assertTrue( add_option( self::OPTION, 'fixture', '', false ) );
		$this->clear_alloptions_cache();
	}

	public function tear_down(): void {
		$this->restore_option( self::OPTION, $this->original_option, $this->option_existed, $this->option_autoloaded );
		$this->restore_option( self::VERSION_OPTION, $this->original_version, $this->version_existed, $this->version_autoloaded );

		parent::tear_down();
	}

	public function test_supported_wordpress_autoload_api_drives_active_and_inactive_policy(): void {
		self::assertArrayNotHasKey( self::OPTION, wp_load_alloptions() );

		OptionPolicy::sync_active();
		$this->clear_alloptions_cache();
		self::assertArrayHasKey( self::OPTION, wp_load_alloptions() );
		self::assertSame( 1, (int) get_option( self::VERSION_OPTION ) );

		OptionPolicy::mark_inactive();
		$this->clear_alloptions_cache();
		self::assertArrayNotHasKey( self::OPTION, wp_load_alloptions() );
		self::assertFalse( get_option( self::VERSION_OPTION, false ) );
	}

	public function test_request_cache_priming_keeps_missing_defaults_absent_and_cached(): void {
		global $wpdb;

		$common = 'cb_core_user_roles_enabled';
		$admin  = 'cb_core_admin_navigation_policy';

		$common_snapshot = $this->snapshot_option( $common );
		$admin_snapshot  = $this->snapshot_option( $admin );

		try {
			delete_option( $common );
			delete_option( $admin );
			$this->clear_option_runtime_cache( $common );
			$this->clear_option_runtime_cache( $admin );

			// wp_prime_option_caches() consults alloptions before issuing its
			// bounded prime query. Load that shared WordPress cache first so
			// this assertion measures only the option-prime operation itself.
			wp_load_alloptions();

			$before = $wpdb->num_queries;
			OptionPolicy::prime_request_cache( false );
			$after_prime = $wpdb->num_queries;

			self::assertLessThanOrEqual( 1, $after_prime - $before );

			$notoptions = wp_cache_get( 'notoptions', 'options' );
			self::assertIsArray( $notoptions );
			self::assertArrayHasKey( $common, $notoptions );
			self::assertArrayNotHasKey( $admin, $notoptions );

			self::assertSame( '__missing__', get_option( $common, '__missing__' ) );
			self::assertSame( $after_prime, $wpdb->num_queries, 'Primed missing state must not issue another option query.' );

			self::assertNull(
				$wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", $common ) ),
				'Priming must not persist a missing public-v1 default.'
			);

			$this->clear_option_runtime_cache( $common );
			$this->clear_option_runtime_cache( $admin );
			OptionPolicy::prime_request_cache( true );

			$notoptions = wp_cache_get( 'notoptions', 'options' );
			self::assertIsArray( $notoptions );
			self::assertArrayHasKey( $common, $notoptions );
			self::assertArrayHasKey( $admin, $notoptions );
		} finally {
			$this->restore_snapshot( $common, $common_snapshot );
			$this->restore_snapshot( $admin, $admin_snapshot );
		}
	}

	public function test_request_cache_prime_runs_before_subsystem_boot(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/Core.php' );
		self::assertIsString( $source );

		$prime = strpos( $source, 'OptionPolicy::prime_request_cache( RequestContext::is_admin_screen() );' );
		$migration = strpos( $source, 'MigrationRecovery::boot();' );
		$permissions = strpos( $source, '\\CoreBlueprint\\Core\\Permissions\\Bootstrap::boot();' );

		self::assertIsInt( $prime );
		self::assertIsInt( $migration );
		self::assertIsInt( $permissions );
		self::assertLessThan( $migration, $prime );
		self::assertLessThan( $permissions, $prime );
	}

	public function test_option_policy_has_no_minimum_version_function_guard(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/OptionPolicy.php' );
		self::assertIsString( $source );
		self::assertStringNotContainsString(
			"function_exists( 'wp_set_option_autoload_values' )",
			$source
		);
		self::assertStringContainsString( 'wp_set_option_autoload_values( $values );', $source );
		self::assertStringNotContainsString(
			"function_exists( 'wp_prime_option_caches' )",
			$source
		);
		self::assertStringContainsString( 'wp_prime_option_caches( $options );', $source );
	}

	/** @return array{exists:bool,value:mixed,autoload:bool} */
	private function snapshot_option( string $name ): array {
		$sentinel = new stdClass();
		$value    = get_option( $name, $sentinel );

		return [
			'exists'   => $value !== $sentinel,
			'value'    => $value,
			'autoload' => array_key_exists( $name, wp_load_alloptions() ),
		];
	}

	/** @param array{exists:bool,value:mixed,autoload:bool} $snapshot */
	private function restore_snapshot( string $name, array $snapshot ): void {
		delete_option( $name );
		if ( $snapshot['exists'] ) {
			add_option( $name, $snapshot['value'], '', $snapshot['autoload'] );
		}
		$this->clear_option_runtime_cache( $name );
	}

	private function clear_option_runtime_cache( string $name ): void {
		wp_cache_delete( $name, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && array_key_exists( $name, $notoptions ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		$this->clear_alloptions_cache();
	}

	private function clear_alloptions_cache(): void {
		wp_cache_delete( 'alloptions', 'options' );
	}

	private function restore_option( string $name, mixed $value, bool $existed, bool $autoload ): void {
		delete_option( $name );
		if ( $existed ) {
			add_option( $name, $value, '', $autoload );
		}
		$this->clear_alloptions_cache();
	}
}
