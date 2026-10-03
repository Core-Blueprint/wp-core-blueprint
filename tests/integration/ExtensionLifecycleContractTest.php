<?php
declare(strict_types=1);

use CoreBlueprint\Core\Ajax\Handlers\ExtensionLifecycle as ExtensionLifecycleActions;
use CoreBlueprint\Core\ExtensionLifecycle;
use CoreBlueprint\Core\Extensions;

final class CB_Base_Extension_Lifecycle_Contract_Test extends WP_UnitTestCase {

	private const ID          = 'core-blueprint-lifecycle-fixture';
	private const PLUGIN_FILE = self::ID . '/' . self::ID . '.php';

	public function set_up(): void {
		parent::set_up();
		$this->remove_fixture();
		$this->create_fixture();
		wp_clean_plugins_cache( true );
		Extensions::invalidate_cache();
	}

	public function tear_down(): void {
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( self::PLUGIN_FILE ) ) {
			deactivate_plugins( self::PLUGIN_FILE, true );
		}
		$this->remove_fixture();
		wp_clean_plugins_cache( true );
		Extensions::invalidate_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_d1_inactive_first_party_extension_remains_discoverable_and_round_trips_native_plugin_state(): void {
		self::assertArrayHasKey( self::ID, Extensions::detected(), 'Inactive first-party fixture was not discovered.' );
		self::assertFalse( ExtensionLifecycle::is_active( self::ID ) );
		self::assertSame( 'activate_plugins', ExtensionLifecycle::capability( self::ID ) );
		self::assertNull( ExtensionLifecycle::extension( 'core-blueprint' ), 'Base itself became a lifecycle target.' );

		$activated = ExtensionLifecycle::set_active( self::ID, true );
		self::assertTrue( $activated === true, is_wp_error( $activated ) ? $activated->get_error_message() : 'Fixture activation failed.' );
		self::assertTrue( is_plugin_active( self::PLUGIN_FILE ), 'WordPress does not report the fixture as active.' );
		self::assertTrue( ExtensionLifecycle::is_active( self::ID ), 'Base lifecycle projection did not reflect activation.' );

		$deactivated = ExtensionLifecycle::set_active( self::ID, false );
		self::assertTrue( $deactivated === true, is_wp_error( $deactivated ) ? $deactivated->get_error_message() : 'Fixture deactivation failed.' );
		self::assertFalse( is_plugin_active( self::PLUGIN_FILE ), 'WordPress still reports the fixture as active.' );
		self::assertFalse( ExtensionLifecycle::is_active( self::ID ), 'Base lifecycle projection did not reflect deactivation.' );
		self::assertArrayHasKey( self::ID, Extensions::detected(), 'Deactivated first-party fixture disappeared from Base inventory.' );
	}

	public function test_d1_extension_lifecycle_ajax_is_authenticated_only(): void {
		ExtensionLifecycleActions::init();

		self::assertNotFalse(
			has_action( 'wp_ajax_cb_core_set_extension_active', [ ExtensionLifecycleActions::class, 'set_active' ] ),
			'Base-owned extension lifecycle AJAX authority is not registered.'
		);
		self::assertFalse(
			has_action( 'wp_ajax_nopriv_cb_core_set_extension_active' ),
			'Extension lifecycle unexpectedly exposes a nopriv route.'
		);
	}


	public function test_d1_status_aware_extension_keeps_plugin_and_runtime_controls_separate(): void {
		$dashboard = (string) file_get_contents( CB_CORE_DIR . 'src/Admin/Pages/Dashboard.php' );

		self::assertStringContainsString( "\$extension['status_id']", $dashboard );
		self::assertStringContainsString( 'ActivationRegistry::definition( $status_id )', $dashboard );
		self::assertStringContainsString( "'data-cb-core-module-action'  => \$status_id", $dashboard );
		self::assertStringContainsString( "'data-cb-core-extension-action' => \$extension_id", $dashboard );
		self::assertStringContainsString( "__( 'Deactivate' )", $dashboard );
		self::assertStringContainsString( "__( 'Activate' )", $dashboard );
	}

	private function create_fixture(): void {
		$directory = WP_PLUGIN_DIR . '/' . self::ID;
		self::assertTrue( wp_mkdir_p( $directory ), 'Could not create extension lifecycle fixture directory.' );

		$plugin = <<<'PHP'
<?php
/**
 * Plugin Name: Core Blueprint Lifecycle Fixture
 * Author: Core Blueprint
 * Version: 1.0.0
 */
defined( 'ABSPATH' ) || exit;
PHP;

		self::assertNotFalse(
			file_put_contents( $directory . '/' . self::ID . '.php', $plugin ),
			'Could not write extension lifecycle fixture plugin.'
		);
	}

	private function remove_fixture(): void {
		$file      = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
		$directory = dirname( $file );
		if ( is_file( $file ) ) {
			unlink( $file );
		}
		if ( is_dir( $directory ) ) {
			rmdir( $directory );
		}
	}
}
