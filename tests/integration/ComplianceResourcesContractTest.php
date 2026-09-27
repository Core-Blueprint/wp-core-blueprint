<?php
declare(strict_types=1);

use CB\Core\Compliance\Admin\Page as CompliancePage;
use CB\Core\Compliance\Repository;
use CB\Core\Compliance\Resolver;
use CB\Core\Compliance\ResourceRegistry;
use CB\Core\Compliance\Shortcode;
use CB\Core\ExtensionRegistry;

final class CB_Base_Compliance_Resources_Contract_Test extends WP_UnitTestCase {

	private const EXTENSION_ID   = 'core-blueprint-compliance-fixture';
	private const EXTENSION_FILE = self::EXTENSION_ID . '/' . self::EXTENSION_ID . '.php';

	public function set_up(): void {
		parent::set_up();
		delete_option( Repository::OPTION_ASSIGNMENTS );
		delete_option( Repository::OPTION_CUSTOM );
		delete_option( 'wp_page_for_privacy_policy' );
		$this->remove_fixture();
		$this->create_fixture();
		wp_clean_plugins_cache( true );

		ExtensionRegistry::reset();
		ResourceRegistry::_reset_for_testing();
		add_action( 'cb_core_register_extensions', [ $this, 'register_extension' ] );
		add_action( 'cb_core_register_compliance_resources', [ $this, 'register_resources' ] );
		ExtensionRegistry::collect();
		ResourceRegistry::collect();
	}

	public function tear_down(): void {
		remove_action( 'cb_core_register_extensions', [ $this, 'register_extension' ] );
		remove_action( 'cb_core_register_compliance_resources', [ $this, 'register_resources' ] );
		delete_option( Repository::OPTION_ASSIGNMENTS );
		delete_option( Repository::OPTION_CUSTOM );
		delete_option( 'wp_page_for_privacy_policy' );
		ResourceRegistry::_reset_for_testing();
		ExtensionRegistry::reset();
		$this->remove_fixture();
		wp_clean_plugins_cache( true );
		parent::tear_down();
	}

	public function register_extension(): void {
		ExtensionRegistry::register( [
			'id'            => self::EXTENSION_ID,
			'plugin_file'   => self::EXTENSION_FILE,
			'requires_api'  => '1.0',
			'requires_base' => '',
			'menu_url'      => '',
			'status_id'     => '',
		] );
	}

	public function register_resources(): void {
		ResourceRegistry::register(
			self::EXTENSION_ID,
			'cancellation-policy',
			[
				'label'       => 'Cancellation Policy',
				'description' => 'Fixture cancellation rules.',
			]
		);
	}

	public function test_base_and_extension_roles_are_registered_with_stable_owner_qualified_keys(): void {
		$base = ResourceRegistry::get( 'core-blueprint:privacy-policy' );
		self::assertNotNull( $base );
		self::assertFalse( $base['custom'] );

		$extension = ResourceRegistry::get( self::EXTENSION_ID . ':cancellation-policy' );
		self::assertNotNull( $extension );
		self::assertSame( self::EXTENSION_ID, $extension['owner'] );
		self::assertFalse( $extension['custom'] );
	}

	public function test_only_site_created_roles_can_be_deleted(): void {
		$custom_key = Repository::add_custom( ResourceRegistry::BASE_OWNER, 'Accessibility Statement', 'Fixture custom role.' );
		self::assertNotSame( '', $custom_key );
		$custom = ResourceRegistry::get( $custom_key );
		self::assertNotNull( $custom );
		self::assertTrue( $custom['custom'] );

		self::assertFalse( Repository::delete_custom( 'core-blueprint:privacy-policy' ) );
		self::assertTrue( Repository::delete_custom( $custom_key ) );
		self::assertNull( ResourceRegistry::get( $custom_key ) );
	}

	public function test_page_document_and_locale_resolution_are_plugin_agnostic(): void {
		$default_page = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Privacy statement',
		] );
		$document = self::factory()->post->create( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => 'English privacy PDF',
			'post_mime_type' => 'application/pdf',
			'guid'           => 'https://example.org/english-privacy.pdf',
		] );

		$key = 'core-blueprint:privacy-policy';
		self::assertTrue( Repository::set_assignment(
			$key,
			[ 'type' => 'page', 'object_id' => $default_page ],
			[ 'en_GB' => [ 'type' => 'document', 'object_id' => $document ] ]
		) );

		$english = Resolver::resolve( $key, 'en_GB' );
		self::assertNotNull( $english );
		self::assertSame( 'document', $english['reference']['type'] );
		self::assertSame( 'en_GB', $english['used_locale'] );

		$dutch = Resolver::resolve( $key, 'nl_NL' );
		self::assertNotNull( $dutch );
		self::assertSame( 'page', $dutch['reference']['type'] );
		self::assertSame( 'default', $dutch['used_locale'] );
	}

	public function test_existing_wordpress_privacy_page_is_used_as_non_destructive_fallback(): void {
		$page_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'WordPress Privacy Policy',
		] );
		update_option( 'wp_page_for_privacy_policy', $page_id );

		$resolved = Resolver::resolve( 'core-blueprint:privacy-policy', 'nl_NL' );
		self::assertNotNull( $resolved );
		self::assertSame( 'wordpress', $resolved['used_locale'] );
		self::assertSame( $page_id, $resolved['reference']['object_id'] );
		self::assertSame( $page_id, (int) get_option( 'wp_page_for_privacy_policy' ) );
	}

	public function test_shortcode_exposes_link_or_url_without_owning_the_content(): void {
		$page_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Terms',
		] );
		$key = 'core-blueprint:terms-and-conditions';
		Repository::set_assignment( $key, [ 'type' => 'page', 'object_id' => $page_id ], [] );

		$link = Shortcode::render( [ 'id' => $key ] );
		self::assertStringContainsString( '<a ', $link );
		self::assertStringContainsString( 'Terms &amp; Conditions', $link );

		$url = Shortcode::render( [ 'id' => $key, 'format' => 'url' ] );
		self::assertSame( esc_url( (string) get_permalink( $page_id ) ), $url );
	}

	public function test_non_document_media_is_rejected(): void {
		$image = self::factory()->post->create( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => 'Image',
			'post_mime_type' => 'image/png',
			'guid'           => 'https://example.org/image.png',
		] );
		self::assertNull( Resolver::parse_reference( 'document:' . $image ) );
	}

	public function test_repository_rejects_invalid_direct_assignments(): void {
		$key = 'core-blueprint:privacy-policy';

		self::assertFalse( Repository::set_assignment(
			$key,
			[ 'type' => 'page', 'object_id' => 999999 ],
			[]
		) );

		$page_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Valid privacy page',
		] );
		self::assertFalse( Repository::set_assignment(
			$key,
			[ 'type' => 'page', 'object_id' => $page_id ],
			[ 'not a locale' => [ 'type' => 'page', 'object_id' => $page_id ] ]
		) );

		self::assertSame(
			[ 'default' => null, 'locales' => [] ],
			Repository::assignment( $key )
		);
	}

	public function test_compliance_is_a_base_owned_central_surface_before_preferences(): void {
		$page = new CompliancePage();
		self::assertSame( 'core-blueprint-compliance', $page->slug() );
		self::assertSame( 'Compliance', $page->title() );
		self::assertSame( 40, $page->position() );
	}

	private function create_fixture(): void {
		$directory = WP_PLUGIN_DIR . '/' . self::EXTENSION_ID;
		self::assertTrue( wp_mkdir_p( $directory ) );
		$plugin = <<<'PHP'
<?php
/**
 * Plugin Name: Core Blueprint Compliance Fixture
 * Author: Core Blueprint
 * Version: 1.0.0
 */
defined( 'ABSPATH' ) || exit;
PHP;
		self::assertNotFalse( file_put_contents( $directory . '/' . self::EXTENSION_ID . '.php', $plugin ) );
	}

	private function remove_fixture(): void {
		$file      = WP_PLUGIN_DIR . '/' . self::EXTENSION_FILE;
		$directory = dirname( $file );
		if ( is_file( $file ) ) {
			unlink( $file );
		}
		if ( is_dir( $directory ) ) {
			rmdir( $directory );
		}
	}
}
