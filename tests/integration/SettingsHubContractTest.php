<?php
declare(strict_types=1);

use CB\Core\Admin\Pages\Settings as SettingsPage;
use CB\Core\Admin\SettingsRegistry;
use CB\Core\ExtensionRegistry;
use CB\Core\Permissions\PrivilegedAccessRegistry;

final class CB_Base_Settings_Hub_Contract_Test extends WP_UnitTestCase {

	private const FIRST_ID          = 'core-blueprint-settings-fixture';
	private const FIRST_PLUGIN_FILE = self::FIRST_ID . '/' . self::FIRST_ID . '.php';
	private const THIRD_ID          = 'agency-settings-fixture';
	private const THIRD_PLUGIN_FILE = self::THIRD_ID . '/' . self::THIRD_ID . '.php';

	public function set_up(): void {
		parent::set_up();
		$this->remove_fixtures();
		$this->create_fixtures();
		wp_clean_plugins_cache( true );

		ExtensionRegistry::reset();
		SettingsRegistry::_reset_for_testing();
		add_action( 'cb_core_register_extensions', [ $this, 'register_extensions' ] );
		add_action( 'cb_core_register_settings', [ $this, 'register_settings' ] );
		ExtensionRegistry::collect();
		$_GET = [];
	}

	public function tear_down(): void {
		remove_action( 'cb_core_register_extensions', [ $this, 'register_extensions' ] );
		remove_action( 'cb_core_register_settings', [ $this, 'register_settings' ] );
		SettingsRegistry::_reset_for_testing();
		ExtensionRegistry::reset();
		wp_set_current_user( 0 );
		$_GET = [];
		$this->remove_fixtures();
		wp_clean_plugins_cache( true );
		parent::tear_down();
	}

	public function register_extensions(): void {
		ExtensionRegistry::register( [
			'id'            => self::FIRST_ID,
			'plugin_file'   => self::FIRST_PLUGIN_FILE,
			'requires_api'  => '1.0',
			'requires_base' => '',
			'menu_url'      => '',
			'status_id'     => '',
		] );
		ExtensionRegistry::register( [
			'id'            => self::THIRD_ID,
			'plugin_file'   => self::THIRD_PLUGIN_FILE,
			'requires_api'  => '1.0',
			'requires_base' => '',
			'menu_url'      => '',
			'status_id'     => '',
		] );
	}

	public function register_settings(): void {
		SettingsRegistry::register( self::FIRST_ID, [
			'label'        => 'Official Fixture',
			'description'  => 'Official settings fixture.',
			'group'        => SettingsRegistry::GROUP_CONTENT_PUBLISHING,
			'capability'   => 'manage_options',
			'renderer'     => [ $this, 'render_first_party' ],
			'icon'         => 'settings',
			'support_url'  => 'https://coreblueprint.io/support',
			'requirements' => [
				'foundations' => [ 'toast' ],
				'components'  => [ 'cards', 'detail-rows' ],
			],
		] );

		SettingsRegistry::register( self::THIRD_ID, [
			'label'        => 'Agency Fixture',
			'description'  => 'Third-party settings fixture.',
			'group'        => SettingsRegistry::GROUP_BUSINESS,
			'capability'   => 'edit_posts',
			'renderer'     => [ $this, 'render_third_party' ],
			'icon'         => 'settings',
			'support_url'  => 'https://example.test/support',
			'requirements' => [
				'components' => [ 'panels' ],
			],
		] );
	}

	public function render_first_party(): void {
		echo '<section id="official-fixture-settings">Official renderer</section>';
	}

	public function render_third_party(): void {
		echo '<section id="agency-fixture-settings">Agency renderer</section>';
	}

	public function test_first_party_provenance_and_developer_are_derived_from_extension_identity(): void {
		$identity = ExtensionRegistry::identity( self::FIRST_ID );
		self::assertNotNull( $identity );
		self::assertTrue( $identity['first_party'] );
		self::assertSame( 'Core Blueprint', $identity['developer_name'] );
		self::assertTrue( ExtensionRegistry::is_first_party( self::FIRST_ID ) );

		$provider = SettingsRegistry::get( self::FIRST_ID );
		self::assertNotNull( $provider );
		self::assertTrue( $provider['first_party'] );
		self::assertSame( 'Core Blueprint', $provider['developer_name'] );
		self::assertSame( 'https://coreblueprint.io', untrailingslashit( $provider['developer_url'] ) );
		self::assertSame( [ 'toast' ], $provider['requirements']['foundations'] );
		self::assertSame( [ 'cards', 'detail-rows' ], $provider['requirements']['components'] );
	}

	public function test_third_party_provenance_and_developer_remain_explicit(): void {
		$identity = ExtensionRegistry::identity( self::THIRD_ID );
		self::assertNotNull( $identity );
		self::assertFalse( $identity['first_party'] );
		self::assertSame( 'Acme Studio', $identity['developer_name'] );
		self::assertFalse( ExtensionRegistry::is_first_party( self::THIRD_ID ) );

		$provider = SettingsRegistry::get( self::THIRD_ID );
		self::assertNotNull( $provider );
		self::assertFalse( $provider['first_party'] );
		self::assertSame( 'Acme Studio', $provider['developer_name'] );
		self::assertSame( 'https://example.test/support', $provider['support_url'] );
	}

	public function test_visibility_is_filtered_by_each_provider_capability(): void {
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );
		$visible = SettingsRegistry::visible();
		self::assertArrayNotHasKey( self::FIRST_ID, $visible );
		self::assertArrayHasKey( self::THIRD_ID, $visible );

		$admin_id = $this->create_approved_administrator();
		wp_set_current_user( $admin_id );
		$visible = SettingsRegistry::visible();
		self::assertArrayHasKey( self::FIRST_ID, $visible );
		self::assertArrayHasKey( self::THIRD_ID, $visible );
	}

	public function test_canonical_url_preserves_provider_queries_but_not_base_route_overrides(): void {
		$url = SettingsRegistry::url( self::FIRST_ID, [
			'tab'       => 'integrations',
			'page'      => 'evil-page',
			'extension' => self::THIRD_ID,
		] );
		$query = [];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		self::assertSame( SettingsPage::SLUG, $query['page'] ?? '' );
		self::assertSame( self::FIRST_ID, $query['extension'] ?? '' );
		self::assertSame( 'integrations', $query['tab'] ?? '' );
	}

	public function test_extensions_is_the_final_base_owned_menu_item(): void {
		$page = new SettingsPage();
		self::assertSame( 'Extensions', $page->title() );
		self::assertSame( 'Extensions', $page->menu_title() );
		self::assertSame( 99, $page->position() );
	}

	public function test_overview_always_shows_provenance_and_developer_identity(): void {
		$admin_id = $this->create_approved_administrator();
		wp_set_current_user( $admin_id );

		ob_start();
		( new SettingsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Extensions<', $html );
		self::assertStringContainsString( 'Official Core Blueprint Extensions', $html );
		self::assertStringContainsString( 'Third-Party Extensions', $html );
		self::assertStringContainsString( 'Developer: Core Blueprint', $html );
		self::assertStringContainsString( 'Developer: Acme Studio', $html );
		self::assertStringContainsString( 'Support is provided by the extension developer, not by Core Blueprint.', $html );
		self::assertStringContainsString( 'cb-core-tab-cards--fixed-three', $html );
	}

	public function test_extensions_directory_keeps_incomplete_rows_at_the_three_column_card_width(): void {
		$css = file_get_contents( CB_CORE_DIR . 'assets/css/components/overview-framework.css' );
		self::assertIsString( $css );
		self::assertStringContainsString( '.cb-core-tab-cards--fixed-three', $css );
		self::assertStringContainsString( 'grid-template-columns: repeat(3, minmax(0, 1fr));', $css );
	}

	public function test_direct_third_party_route_keeps_support_boundary_visible(): void {
		$admin_id = $this->create_approved_administrator();
		wp_set_current_user( $admin_id );
		$_GET['extension'] = self::THIRD_ID;

		ob_start();
		( new SettingsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Third-party extension', $html );
		self::assertStringContainsString( 'Developer:', $html );
		self::assertStringContainsString( 'Acme Studio', $html );
		self::assertStringContainsString( 'not by Core Blueprint', $html );
		self::assertStringContainsString( 'https://example.test/support', $html );
		self::assertStringContainsString( 'agency-fixture-settings', $html );
	}

	private function create_approved_administrator(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'test_fixture' ) );
		return $user_id;
	}

	private function create_fixtures(): void {
		$this->create_fixture(
			self::FIRST_ID,
			<<<'PHP'
<?php
/**
 * Plugin Name: Core Blueprint Settings Fixture
 * Author: Core Blueprint
 * Author URI: https://coreblueprint.io
 * Version: 1.0.0
 */
defined( 'ABSPATH' ) || exit;
PHP
		);

		$this->create_fixture(
			self::THIRD_ID,
			<<<'PHP'
<?php
/**
 * Plugin Name: Agency Settings Fixture
 * Author: Acme Studio
 * Author URI: https://example.test
 * Version: 1.0.0
 */
defined( 'ABSPATH' ) || exit;
PHP
		);
	}

	private function create_fixture( string $id, string $plugin ): void {
		$directory = WP_PLUGIN_DIR . '/' . $id;
		self::assertTrue( wp_mkdir_p( $directory ), 'Could not create settings fixture directory.' );
		self::assertNotFalse(
			file_put_contents( $directory . '/' . $id . '.php', $plugin ),
			'Could not write settings fixture plugin.'
		);
	}

	private function remove_fixtures(): void {
		foreach ( [ self::FIRST_PLUGIN_FILE, self::THIRD_PLUGIN_FILE ] as $plugin_file ) {
			$file      = WP_PLUGIN_DIR . '/' . $plugin_file;
			$directory = dirname( $file );
			if ( is_file( $file ) ) {
				unlink( $file );
			}
			if ( is_dir( $directory ) ) {
				rmdir( $directory );
			}
		}
	}
}
