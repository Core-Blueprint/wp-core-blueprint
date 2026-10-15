<?php
declare(strict_types=1);

use CoreBlueprint\Core\TemplateLibrary\BrowserController;
use CoreBlueprint\Core\TemplateLibrary\DesignerScope;
use CoreBlueprint\Core\TemplateLibrary\TemplateManagementSourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateMetadata;
use CoreBlueprint\Core\TemplateLibrary\TemplateNavigation;
use CoreBlueprint\Core\TemplateLibrary\TemplatePage;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

final class CB_Template_Library_Management_Contract_Test extends WP_UnitTestCase {
	private int $operator;

	public function set_up(): void {
		parent::set_up();
		$this->operator = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->operator );
		set_current_screen( 'core-blueprint_page_core-blueprint-template-library' );
		TemplateSourceRegistry::_reset_for_testing();
		add_action( 'core_blueprint_register_template_sources', [ $this, 'register_source' ] );
	}

	public function tear_down(): void {
		remove_action( 'core_blueprint_register_template_sources', [ $this, 'register_source' ] );
		TemplateSourceRegistry::_reset_for_testing();
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function register_source(): void {
		TemplateSourceRegistry::register( new class implements TemplateManagementSourceInterface {
			public function source_id(): string { return 'core-blueprint-managed-fixture'; }
			public function extension_id(): string { return 'core-blueprint'; }
			public function designer_type(): string { return 'workflow'; }
			public function can_browse( int $user_id ): bool {
				return user_can( $user_id, 'manage_options' );
			}
			public function can_view( string $template_id, int $user_id ): bool {
				return in_array( $template_id, [ 'personal-one', 'site-one', 'built-in-one' ], true )
					&& $this->can_browse( $user_id );
			}
			public function can_manage( string $template_id, int $user_id ): bool {
				return 'personal-one' === $template_id && $this->can_browse( $user_id );
			}
			public function navigation( TemplateMetadata $item, int $user_id ): TemplateNavigation {
				return new TemplateNavigation(
					[ 'url' => admin_url( 'admin.php?page=fixture&preview=' . $item->id ),
						'label' => 'Preview' ],
					[ 'url' => admin_url( 'admin.php?page=fixture&edit=' . $item->id ),
						'label' => 'Manage' ]
				);
			}
			public function query( TemplateQuery $query, ?string $cursor ): TemplatePage {
				$items = [];
				foreach ( [
					[ 'personal-one', 'personal' ],
					[ 'site-one', 'site' ],
					[ 'built-in-one', 'built-in' ],
				] as [ $id, $origin ] ) {
					if ( '' !== $query->origin && $query->origin !== $origin ) {
						continue;
					}
					$items[] = new TemplateMetadata(
						$this->source_id(), 'core-blueprint', 'workflow', $id,
						'Fixture ' . $id, 'Metadata only', $origin, 'demo', 'en', '1'
					);
				}
				return new TemplatePage( array_slice( $items, 0, $query->limit ) );
			}
		} );
	}

	public function test_global_navigation_is_scoped_to_per_item_management_authority(): void {
		$response = BrowserController::templates( new WP_REST_Request(
			'GET', '/core-blueprint/v1/template-library/templates'
		) );
		self::assertSame( 200, $response->get_status() );
		$items = $response->get_data()['items'];
		self::assertCount( 3, $items );
		self::assertArrayHasKey( 'preview', $items[0]['navigation'] );
		self::assertArrayHasKey( 'manage', $items[0]['navigation'] );
		self::assertArrayNotHasKey( 'manage', $items[1]['navigation'], 'Cannot manage a site template without domain permission.' );
		self::assertArrayNotHasKey( 'manage', $items[2]['navigation'], 'Immutable built-in defaults cannot be overwritten.' );
		foreach ( $items as $item ) {
			self::assertArrayNotHasKey( 'definition', $item );
			self::assertArrayNotHasKey( 'payload', $item );
			self::assertStringStartsWith( admin_url(), $item['navigation']['preview']['url'] );
		}
	}

	public function test_designer_scope_never_exposes_management_actions(): void {
		$token = DesignerScope::issue( 'core-blueprint', 'workflow' );
		self::assertIsString( $token );
		$request = new WP_REST_Request( 'GET', '/core-blueprint/v1/template-library/templates' );
		$request->set_header( 'X-CB-Designer-Scope', $token );
		$items = BrowserController::templates( $request )->get_data()['items'];
		self::assertCount( 3, $items );
		foreach ( $items as $item ) {
			self::assertArrayHasKey( 'preview', $item['navigation'] );
			self::assertArrayNotHasKey( 'manage', $item['navigation'] );
		}
	}

	public function test_untrusted_navigation_and_unauthorized_users_fail_closed(): void {
		foreach ( [
			'javascript:alert(1)',
			'https://untrusted.example/wp-admin/admin.php',
			admin_url( 'admin-post.php?action=delete' ),
			admin_url( 'admin-ajax.php?action=delete' ),
			admin_url( 'admin.php/../admin-post.php' ),
		] as $url ) {
			try {
				new TemplateNavigation( [ 'url' => $url, 'label' => 'Unsafe' ] );
				self::fail( 'Unsafe destination accepted.' );
			} catch ( \InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
		TemplateSourceRegistry::sources();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$forged = new TemplateMetadata(
			'core-blueprint-managed-fixture', 'core-blueprint', 'workflow',
			'personal-one', 'Forbidden', '', 'personal', 'demo', 'en', '1'
		);
		self::assertSame( [], TemplateSourceRegistry::navigation( $forged, true )->public_links( true ) );
		self::assertSame( [], BrowserController::templates( new WP_REST_Request(
			'GET', '/core-blueprint/v1/template-library/templates'
		) )->get_data()['items'] );
	}

	public function test_legacy_provider_without_management_contract_stays_compatible(): void {
		$unregistered = new TemplateMetadata(
			'core-blueprint-unknown-source', 'core-blueprint', 'workflow',
			'personal-one', 'Unsafe', '', 'personal', 'demo', 'en', '1'
		);
		self::assertSame( [], TemplateSourceRegistry::navigation( $unregistered, true )->public_links( true ) );
	}
}
