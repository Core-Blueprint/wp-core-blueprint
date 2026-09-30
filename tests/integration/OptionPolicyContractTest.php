<?php
declare(strict_types=1);

use CB\Core\OptionPolicy;

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

	public function test_option_policy_has_no_minimum_version_function_guard(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/OptionPolicy.php' );
		self::assertIsString( $source );
		self::assertStringNotContainsString(
			"function_exists( 'wp_set_option_autoload_values' )",
			$source
		);
		self::assertStringContainsString( 'wp_set_option_autoload_values( $values );', $source );
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
