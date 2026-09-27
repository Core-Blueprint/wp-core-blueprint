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

	public function test_registration_lifecycle_owner_and_reserved_custom_namespace_are_fail_closed(): void {
		self::assertFalse( ResourceRegistry::register(
			self::EXTENSION_ID,
			'outside-lifecycle',
			[ 'label' => 'Outside lifecycle' ]
		) );

		$results = [];
		$probe = static function () use ( &$results ): void {
			$results['unknown_owner'] = ResourceRegistry::register(
				'unknown-extension',
				'policy',
				[ 'label' => 'Unknown owner policy' ]
			);
			$results['duplicate'] = ResourceRegistry::register(
				self::EXTENSION_ID,
				'cancellation-policy',
				[ 'label' => 'Duplicate policy' ]
			);
			$results['reserved_custom'] = ResourceRegistry::register(
				self::EXTENSION_ID,
				'custom-reserved',
				[ 'label' => 'Reserved custom namespace' ]
			);
		};
		add_action( 'cb_core_register_compliance_resources', $probe, 20 );
		do_action( 'cb_core_register_compliance_resources' );
		remove_action( 'cb_core_register_compliance_resources', $probe, 20 );

		self::assertFalse( $results['unknown_owner'] );
		self::assertFalse( $results['duplicate'] );
		self::assertFalse( $results['reserved_custom'] );
	}

	public function test_custom_resource_metadata_can_be_updated_without_changing_assignment(): void {
		$key = Repository::add_custom( ResourceRegistry::BASE_OWNER, 'Accessibility Statement', 'Original description.' );
		self::assertNotSame( '', $key );

		$page_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Accessibility',
		] );
		self::assertTrue( Repository::set_assignment( $key, [ 'type' => 'page', 'object_id' => $page_id ], [] ) );

		self::assertTrue( Repository::update_custom( $key, 'Accessibility Policy', 'Updated description.' ) );
		$updated = ResourceRegistry::get( $key );
		self::assertNotNull( $updated );
		self::assertSame( 'Accessibility Policy', $updated['label'] );
		self::assertSame( 'Updated description.', $updated['description'] );
		self::assertSame( $page_id, Repository::assignment( $key )['default']['object_id'] );
		self::assertFalse( Repository::update_custom( 'core-blueprint:privacy-policy', 'Changed', '' ) );
	}

	public function test_language_only_variant_is_used_before_default_assignment(): void {
		$default_page = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Default privacy',
		] );
		$english_page = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'English privacy',
		] );

		$key = 'core-blueprint:privacy-policy';
		self::assertTrue( Repository::set_assignment(
			$key,
			[ 'type' => 'page', 'object_id' => $default_page ],
			[ 'en' => [ 'type' => 'page', 'object_id' => $english_page ] ]
		) );

		$resolved = Resolver::resolve( $key, 'en_GB' );
		self::assertNotNull( $resolved );
		self::assertSame( 'en', $resolved['used_locale'] );
		self::assertSame( $english_page, $resolved['reference']['object_id'] );
	}

	public function test_unpublished_deleted_and_disallowed_resources_fail_closed(): void {
		$key = 'core-blueprint:disclaimer';
		$page_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Disclaimer',
		] );
		self::assertTrue( Repository::set_assignment( $key, [ 'type' => 'page', 'object_id' => $page_id ], [] ) );
		self::assertNotNull( Resolver::resolve( $key, 'nl_NL' ) );

		wp_update_post( [ 'ID' => $page_id, 'post_status' => 'draft' ] );
		self::assertNull( Resolver::resolve( $key, 'nl_NL' ) );

		$document = self::factory()->post->create( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => 'Disclaimer PDF',
			'post_mime_type' => 'application/pdf',
			'guid'           => 'https://example.org/disclaimer.pdf',
		] );
		self::assertTrue( Repository::set_assignment( $key, [ 'type' => 'document', 'object_id' => $document ], [] ) );
		self::assertNotNull( Resolver::resolve( $key, 'nl_NL' ) );

		$deny_pdf = static fn( array $mimes ): array => [ 'text/plain' ];
		add_filter( 'cb_core_compliance_document_mime_types', $deny_pdf );
		self::assertNull( Resolver::resolve( $key, 'nl_NL' ) );
		remove_filter( 'cb_core_compliance_document_mime_types', $deny_pdf );

		wp_delete_attachment( $document, true );
		self::assertNull( Resolver::resolve( $key, 'nl_NL' ) );
	}


	public function test_unavailable_assignment_keeps_human_readable_admin_context_without_becoming_resolvable(): void {
		$key = 'core-blueprint:disclaimer';
		$page_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Disclaimer',
		] );
		self::assertTrue( Repository::set_assignment( $key, [ 'type' => 'page', 'object_id' => $page_id ], [] ) );

		wp_update_post( [ 'ID' => $page_id, 'post_status' => 'draft' ] );

		$item = Resolver::assignment_item( Repository::assignment( $key )['default'] );
		self::assertNotNull( $item );
		self::assertSame( 'page:' . $page_id, $item['id'] );
		self::assertSame( 'Disclaimer', $item['label'] );
		self::assertSame( 'Draft page', $item['meta'] );
		self::assertNull( Resolver::picker_item( Repository::assignment( $key )['default'] ) );
		self::assertNull( Resolver::resolve( $key, 'nl_NL' ) );
	}

	public function test_compliance_admin_uses_canonical_object_picker_and_audited_mutations(): void {
		$root    = dirname( __DIR__, 2 );
		$page    = (string) file_get_contents( $root . '/src/Compliance/Admin/Page.php' );
		$actions = (string) file_get_contents( $root . '/src/Compliance/Admin/Actions.php' );

		self::assertStringContainsString( 'use CB\\Core\\UI\\ObjectPicker;', $page );
		self::assertStringContainsString( 'ObjectPicker::render(', $page );
		self::assertStringContainsString( 'cb-core-interactive-row', $page );
		self::assertStringContainsString( 'cb-core-disclosure--compact', $page );
		self::assertStringContainsString( "Icon::render( 'expand'", $page );
		self::assertStringContainsString( 'StateBadge::render(', $page );
		self::assertStringContainsString( "'cb_resource'", $actions );
		self::assertStringNotContainsString( 'cb-core-card cb-core-card--spacious', $page );
		self::assertStringNotContainsString( 'data-cb-core-object-picker', $page );
		self::assertStringContainsString( 'compliance.resource.assignment_changed', $actions );
		self::assertStringContainsString( 'compliance.resource.custom_added', $actions );
		self::assertStringContainsString( 'compliance.resource.custom_updated', $actions );
		self::assertStringContainsString( 'compliance.resource.custom_deleted', $actions );
	}

	public function test_uninstall_owns_only_compliance_configuration_not_referenced_content(): void {
		$uninstall = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		self::assertStringContainsString( "'cb_core_compliance_resource_assignments'", $uninstall );
		self::assertStringContainsString( "'cb_core_compliance_custom_resources'", $uninstall );
		self::assertStringNotContainsString( 'wp_delete_post(', $uninstall );
		self::assertStringNotContainsString( 'wp_delete_attachment(', $uninstall );
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
