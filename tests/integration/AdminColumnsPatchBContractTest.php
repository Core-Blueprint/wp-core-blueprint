<?php
declare(strict_types=1);

use CB\Core\AdminColumns\Admin\Ajax as AdminColumnsAjax;
use CB\Core\AdminColumns\Admin\ScreenSettings;
use CB\Core\AdminColumns\PolicyRepository;
use CB\Core\AdminColumns\RegisteredMetaColumns;
use CB\Core\AdminColumns\Runtime;
use CB\Core\AdminColumns\TaxonomyColumns;
use CB\Core\ContentModels\FieldTypes;
use CB\Core\ContentModels\Repository as ContentModelsRepository;
use CB\Core\Log\AuditLog;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Profiles\Diff;
use CB\Core\Profiles\Engine;
use CB\Core\Profiles\SectionInterface;
use CB\Core\Profiles\SectionRegistry;

final class CB_Admin_Columns_Failing_Profile_Section implements SectionInterface {
	public function id(): string { return 'zz-admin-columns-failing'; }
	public function label(): string { return 'Failing'; }
	public function description(): string { return ''; }
	public function schema_version(): int { return 1; }
	public function supports_schema_version( int $schema_version ): bool { return 1 === $schema_version; }
	public function migrate( array $incoming, int $source_schema_version ): array { return $this->normalize( $incoming ); }
	public function export(): array { return [ 'value' => 'stable' ]; }
	public function normalize( array $incoming ): array { return [ 'value' => (string) ( $incoming['value'] ?? '' ) ]; }
	public function preflight( array $incoming, array $current ): void {}
	public function snapshot(): array { return $this->export(); }
	public function warnings( array $incoming ): array { return []; }
	public function preview( array $current, array $incoming ): array { return Diff::between( $current, $incoming ); }
	public function apply( array $incoming, string $actor ): void { throw new RuntimeException( 'Injected Admin Columns profile failure.' ); }
	public function restore( array $snapshot, array $incoming, string $actor ): void {}
	public function verify( array $incoming ): bool { return false; }
}

final class CB_Base_Admin_Columns_Patch_B_Contract_Test extends WP_UnitTestCase {
	private mixed $saved_policy = '__cb_ac_patch_b_missing__';
	private mixed $saved_content_models = '__cb_ac_patch_b_missing__';

	public function set_up(): void {
		parent::set_up();
		$this->saved_policy = get_option( PolicyRepository::OPTION, '__cb_ac_patch_b_missing__' );
		$this->saved_content_models = get_option( 'cb_core_content_models_schema', '__cb_ac_patch_b_missing__' );
		delete_option( PolicyRepository::OPTION );
		Runtime::_reset_for_testing();
		TaxonomyColumns::_reset_for_testing();
		RegisteredMetaColumns::_reset_for_testing();
	}

	public function tear_down(): void {
		SectionRegistry::_set_for_testing( null );
		unregister_taxonomy( 'cb_ac_topic' );
		unregister_meta_key( 'post', 'cb_ac_generic' );
		unregister_meta_key( 'post', 'cb_ac_subtype', 'post' );
		unregister_meta_key( 'post', 'cb_ac_array', 'post' );
		unregister_meta_key( 'post', 'cb_ac_object', 'post' );
		unregister_meta_key( 'post', 'cb_ac_multi', 'post' );
		unregister_meta_key( 'post', 'cb_ac_cm_field', 'post' );
		unregister_meta_key( 'post', 'cb_ac_render', 'post' );
		unregister_meta_key( 'post', 'cb_ac_collision', 'post' );
		$_POST = [];
		$_REQUEST = [];
		if ( '__cb_ac_patch_b_missing__' === $this->saved_policy ) {
			delete_option( PolicyRepository::OPTION );
		} else {
			update_option( PolicyRepository::OPTION, $this->saved_policy, false );
		}
		if ( '__cb_ac_patch_b_missing__' === $this->saved_content_models ) {
			delete_option( 'cb_core_content_models_schema' );
		} else {
			update_option( 'cb_core_content_models_schema', $this->saved_content_models, false );
		}
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_b1_canonical_writer_noop_has_no_audit_and_real_change_has_one_bounded_audit(): void {
		$events = [];
		$listener = static function ( int $id, string $event, string $severity, array $context ) use ( &$events ): void {
			if ( 'settings_changed' === $event && 'admin_columns_policy' === ( $context['key'] ?? '' ) ) {
				$events[] = $context;
			}
		};
		add_action( 'cb_core_audit_log_written', $listener, 10, 4 );
		try {
			self::assertFalse( PolicyRepository::replace( PolicyRepository::empty_policy(), 'test:no-op' ) );
			self::assertSame( [], $events );

			$policy = PolicyRepository::empty_policy();
			$policy['screens']['edit-post'] = [
				'order' => [ 'cb', 'title', 'date' ],
				'hidden' => [],
				'taxonomies' => [],
				'meta' => [],
			];
			self::assertTrue( PolicyRepository::replace( $policy, 'test:change' ) );
			self::assertCount( 1, $events );
			self::assertSame( [ 'edit-post' ], $events[0]['changed_screens'] );
			self::assertArrayNotHasKey( 'policy', $events[0] );
			self::assertArrayHasKey( 'mutation_id', $events[0] );
		} finally {
			remove_action( 'cb_core_audit_log_written', $listener, 10 );
		}
	}

	public function test_b2_screen_settings_ui_is_admin_only_flat_and_labels_are_presentation_only(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$admin = get_userdata( $admin_id );
		self::assertInstanceOf( WP_User::class, $admin );
		self::assertTrue( PrivilegedAccessRegistry::approve( $admin, 0, 'admin-columns-b2-fixture' ) );
		wp_set_current_user( $admin_id );
		self::assertTrue( current_user_can( 'manage_options' ) );
		$GLOBALS['pagenow'] = 'edit.php';
		$GLOBALS['typenow'] = 'post';
		set_current_screen( 'edit-post' );
		$screen = get_current_screen();
		self::assertInstanceOf( WP_Screen::class, $screen );
		Runtime::attach( $screen );
		apply_filters( 'manage_edit-post_columns', [
			'cb' => 'CB',
			'title' => '<strong>Markup title</strong>',
			'plugin' => '<span class="x">Plugin label</span>',
		] );

		$html = ScreenSettings::render( '', $screen );
		self::assertStringContainsString( 'Site-wide Admin Columns Governance', $html );
		self::assertStringContainsString( 'Plugin label', $html );
		self::assertStringNotContainsString( 'class="x"', $html );
		self::assertStringNotContainsString( '<form', strtolower( $html ) );
		self::assertStringContainsString( 'data-cb-core-reorder', $html );
		self::assertFalse( get_option( PolicyRepository::OPTION, false ), 'Presentation labels/discovery must not persist policy state.' );
		ScreenSettings::enqueue();
		self::assertTrue( wp_style_is( 'cb-core-css-reorder-native', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'cb-core-admin-columns', 'enqueued' ) );

		$GLOBALS['pagenow'] = 'users.php';
		set_current_screen( 'users' );
		$users_screen = get_current_screen();
		self::assertInstanceOf( WP_Screen::class, $users_screen );
		self::assertSame( 'prefix', ScreenSettings::render( 'prefix', $users_screen ) );

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		$GLOBALS['pagenow'] = 'edit.php';
		$GLOBALS['typenow'] = 'post';
		set_current_screen( 'edit-post' );
		$screen = get_current_screen();
		self::assertInstanceOf( WP_Screen::class, $screen );
		self::assertSame( 'prefix', ScreenSettings::render( 'prefix', $screen ) );
	}

	public function test_b3_ajax_contract_is_private_capability_and_nonce_gated(): void {
		\CB\Core\AdminColumns\Admin\Ajax::boot();
		self::assertNotFalse( has_action( 'wp_ajax_' . AdminColumnsAjax::ACTION, [ AdminColumnsAjax::class, 'handle' ] ) );
		$root = dirname( __DIR__, 2 );
		$source = file_get_contents( $root . '/src/AdminColumns/Admin/Ajax.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( 'Request::nonce( self::NONCE_ACTION )', $source );
		self::assertStringContainsString( "Request::cap( 'manage_options' )", $source );
		self::assertStringNotContainsString( 'update_option(', $source );
		self::assertStringNotContainsString( 'delete_option(', $source );
	}

	public function test_b4_taxonomy_addition_uses_native_taxonomy_filter_only(): void {
		register_taxonomy( 'cb_ac_topic', [ 'post' ], [ 'label' => 'Topics', 'show_ui' => true, 'show_admin_column' => false ] );
		PolicyRepository::replace( [
			'schema_version' => 1,
			'screens' => [
				'edit-post' => [
					'order' => [],
					'hidden' => [],
					'taxonomies' => [ 'cb_ac_topic' ],
					'meta' => [],
				],
			],
		], 'test:taxonomy' );

		$taxonomies = TaxonomyColumns::filter_taxonomies( [], 'post' );
		self::assertContains( 'cb_ac_topic', $taxonomies );
		self::assertSame( 'taxonomy-cb_ac_topic', TaxonomyColumns::column_id( 'cb_ac_topic' ) );

		$root = dirname( __DIR__, 2 );
		$source = file_get_contents( $root . '/src/AdminColumns/TaxonomyColumns.php' );
		self::assertStringNotContainsString( 'custom_column', $source );
	}

	public function test_b5_registered_generic_and_subtype_scalar_meta_are_discovered_but_arrays_and_unregistered_are_not(): void {
		register_meta( 'post', 'cb_ac_generic', [ 'type' => 'string', 'single' => true, 'label' => 'Generic' ] );
		register_post_meta( 'post', 'cb_ac_subtype', [ 'type' => 'integer', 'single' => true, 'label' => 'Subtype' ] );
		register_post_meta( 'post', 'cb_ac_array', [ 'type' => 'array', 'single' => true, 'show_in_rest' => [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ] ] );
		register_post_meta( 'post', 'cb_ac_object', [ 'type' => 'object', 'single' => true, 'show_in_rest' => [ 'schema' => [ 'type' => 'object', 'additionalProperties' => [ 'type' => 'string' ] ] ] ] );
		register_post_meta( 'post', 'cb_ac_multi', [ 'type' => 'string', 'single' => false, 'label' => 'Multiple' ] );

		$catalog = RegisteredMetaColumns::catalog( 'post' );
		self::assertArrayHasKey( 'cb_ac_generic', $catalog );
		self::assertArrayHasKey( 'cb_ac_subtype', $catalog );
		self::assertArrayNotHasKey( 'cb_ac_array', $catalog );
		self::assertArrayNotHasKey( 'cb_ac_object', $catalog );
		self::assertArrayNotHasKey( 'cb_ac_multi', $catalog );
		self::assertArrayNotHasKey( 'cb_ac_unregistered', $catalog );
	}

	public function test_b6_content_model_label_is_reused_above_native_registered_meta_contract(): void {
		$group = ContentModelsRepository::normalize_field_group( [
			'title' => 'Admin column fields',
			'post_types' => [ 'post' ],
		] );
		$group = ContentModelsRepository::save_field_group( $group );
		$field = ContentModelsRepository::save_field( (string) $group['id'], [
			'label' => 'First-party reference',
			'name' => 'cb_ac_cm_field',
			'type' => 'text',
		] );
		register_post_meta( 'post', 'cb_ac_cm_field', FieldTypes::meta_args( $field ) );

		$catalog = RegisteredMetaColumns::catalog( 'post' );
		self::assertSame( 'First-party reference', $catalog['cb_ac_cm_field']['label'] );
		self::assertTrue( $catalog['cb_ac_cm_field']['content_model'] );
	}

	public function test_b7_meta_rendering_requires_edit_post_meta_and_is_plain_bounded(): void {
		register_post_meta( 'post', 'cb_ac_render', [ 'type' => 'string', 'single' => true, 'label' => 'Render' ] );
		$post_id = self::factory()->post->create( [ 'post_type' => 'post', 'post_status' => 'publish' ] );
		update_post_meta( $post_id, 'cb_ac_render', '<b>' . str_repeat( 'x', 260 ) . '</b>' );
		PolicyRepository::replace( [
			'schema_version' => 1,
			'screens' => [
				'edit-post' => [
					'order' => [ RegisteredMetaColumns::column_id( 'cb_ac_render' ) ],
					'hidden' => [],
					'taxonomies' => [],
					'meta' => [ 'cb_ac_render' ],
				],
			],
		], 'test:meta' );

		$GLOBALS['pagenow'] = 'edit.php';
		$GLOBALS['typenow'] = 'post';
		set_current_screen( 'edit-post' );
		$screen = get_current_screen();
		self::assertInstanceOf( WP_Screen::class, $screen );
		RegisteredMetaColumns::attach( $screen );
		$generated = apply_filters( 'manage_edit-post_columns', [] );
		self::assertArrayHasKey( RegisteredMetaColumns::column_id( 'cb_ac_render' ), $generated );

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		ob_start();
		do_action( 'manage_post_posts_custom_column', RegisteredMetaColumns::column_id( 'cb_ac_render' ), $post_id );
		self::assertSame( '', (string) ob_get_clean() );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		ob_start();
		do_action( 'manage_post_posts_custom_column', RegisteredMetaColumns::column_id( 'cb_ac_render' ), $post_id );
		$output = (string) ob_get_clean();
		self::assertStringNotContainsString( '<b>', $output );
		self::assertLessThanOrEqual( 205, strlen( html_entity_decode( $output ) ) );

		$root = dirname( __DIR__, 2 );
		$source = file_get_contents( $root . '/src/AdminColumns/RegisteredMetaColumns.php' );
		self::assertStringContainsString( "current_user_can( 'edit_post_meta', \$post_id, \$meta_key )", $source );
		self::assertStringContainsString( "get_registered_metadata( 'post', \$post_id, \$meta_key )", $source );
		self::assertStringContainsString( 'PHP_INT_MAX - 100', $source );
	}

	public function test_b8_generated_meta_column_never_takes_over_an_existing_plugin_column_identity(): void {
		register_post_meta( 'post', 'cb_ac_collision', [ 'type' => 'string', 'single' => true, 'label' => 'Collision' ] );
		$post_id = self::factory()->post->create( [ 'post_type' => 'post', 'post_status' => 'publish' ] );
		update_post_meta( $post_id, 'cb_ac_collision', 'private value' );
		$column_id = RegisteredMetaColumns::column_id( 'cb_ac_collision' );
		PolicyRepository::replace( [
			'schema_version' => 1,
			'screens' => [
				'edit-post' => [
					'order' => [ $column_id ],
					'hidden' => [],
					'taxonomies' => [],
					'meta' => [ 'cb_ac_collision' ],
				],
			],
		], 'test:collision' );

		$GLOBALS['pagenow'] = 'edit.php';
		$GLOBALS['typenow'] = 'post';
		set_current_screen( 'edit-post' );
		$screen = get_current_screen();
		self::assertInstanceOf( WP_Screen::class, $screen );
		RegisteredMetaColumns::attach( $screen );

		$columns = apply_filters( 'manage_edit-post_columns', [ $column_id => 'Plugin-owned column' ] );
		self::assertSame( 'Plugin-owned column', $columns[ $column_id ] );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		ob_start();
		do_action( 'manage_post_posts_custom_column', $column_id, $post_id );
		self::assertSame( '', (string) ob_get_clean() );
	}

	public function test_b9_ajax_save_uses_nonce_and_preserves_wordpress_user_screen_options(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$admin = get_userdata( $admin_id );
		self::assertInstanceOf( WP_User::class, $admin );
		self::assertTrue( PrivilegedAccessRegistry::approve( $admin, 0, 'admin-columns-b9-fixture' ) );
		wp_set_current_user( $admin_id );
		self::assertTrue( current_user_can( 'manage_options' ) );

		$hidden_meta_key = 'manageedit-postcolumnshidden';
		update_user_meta( $admin_id, $hidden_meta_key, [ 'author' ] );

		$_POST = [
			'nonce' => wp_create_nonce( AdminColumnsAjax::NONCE_ACTION ),
			'screen_id' => 'edit-post',
			'operation' => 'save',
			'screen_policy' => (string) wp_json_encode( [
				'order' => [ 'cb', 'title', 'date' ],
				'hidden' => [ 'date' ],
				'taxonomies' => [],
				'meta' => [],
			] ),
		];
		$_REQUEST = $_POST;

		$output = '';
		$die_handler = static function () use ( &$output ): callable {
			return static function () use ( &$output ): void {
				$buffer = ob_get_clean();
				if ( false !== $buffer ) {
					$output .= $buffer;
				}
				throw new RuntimeException( '__cb_admin_columns_wp_die__' );
			};
		};

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die_handler, 1 );
		$buffer_level = ob_get_level();
		ini_set( 'implicit_flush', false );
		ob_start();
		try {
			AdminColumnsAjax::handle();
			self::fail( 'AJAX handler did not terminate through wp_die().' );
		} catch ( RuntimeException $error ) {
			self::assertSame( '__cb_admin_columns_wp_die__', $error->getMessage() );
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				$buffer = ob_get_clean();
				if ( false !== $buffer ) {
					$output .= $buffer;
				}
			}
			remove_filter( 'wp_die_ajax_handler', $die_handler, 1 );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			$_POST = [];
			$_REQUEST = [];
		}

		$payload = json_decode( $output, true );
		self::assertIsArray( $payload );
		self::assertTrue( (bool) ( $payload['success'] ?? false ) );
		self::assertSame( [ 'date' ], PolicyRepository::screen( 'edit-post' )['hidden'] );
		self::assertSame( [ 'author' ], get_user_meta( $admin_id, $hidden_meta_key, true ) );
	}

	public function test_b10_profiles_are_portable_and_rollback_admin_columns_after_later_failure(): void {
		$section = SectionRegistry::get( 'admin-columns' );
		self::assertNotNull( $section );
		self::assertSame( 1, $section->schema_version() );

		$before = PolicyRepository::empty_policy();
		$after = $before;
		$after['screens']['edit-missing_portable'] = [
			'order' => [ 'plugin_future', RegisteredMetaColumns::column_id( 'missing_meta' ) ],
			'hidden' => [ 'plugin_future' ],
			'taxonomies' => [ 'missing_taxonomy' ],
			'meta' => [ 'missing_meta' ],
		];
		$document = Engine::export_document( 'Admin columns', '', [ 'admin-columns' ] );
		$document['sections']['admin-columns']['data'] = $after;
		$preview = Engine::preview( $document );
		Engine::apply( $document, $preview['fingerprint'], 'test:profile' );
		self::assertSame( PolicyRepository::normalize( $after ), PolicyRepository::get() );
		self::assertTrue( $section->verify( PolicyRepository::normalize( $after ) ) );

		PolicyRepository::replace( $before, 'test:reset' );
		SectionRegistry::_set_for_testing( [ new CB_Admin_Columns_Failing_Profile_Section() ] );
		$document = Engine::export_document( 'Admin columns rollback', '', [ 'admin-columns', 'zz-admin-columns-failing' ] );
		$document['sections']['admin-columns']['data'] = $after;
		$document['sections']['zz-admin-columns-failing']['data'] = [ 'value' => 'explode' ];
		$preview = Engine::preview( $document );
		try {
			Engine::apply( $document, $preview['fingerprint'], 'test:rollback' );
			self::fail( 'Injected later Profile failure did not abort.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Injected Admin Columns profile failure', $error->getMessage() );
		}
		self::assertSame( $before, PolicyRepository::get() );
	}

	public function test_b11_assets_and_runtime_do_not_touch_wp_list_table_dom_sorting_queries_or_user_screen_options(): void {
		$root = dirname( __DIR__, 2 );
		$js = file_get_contents( $root . '/assets/js/features/admin-columns.js' );
		$meta = file_get_contents( $root . '/src/AdminColumns/RegisteredMetaColumns.php' );
		$screen = file_get_contents( $root . '/src/AdminColumns/Admin/ScreenSettings.php' );
		self::assertIsString( $js );
		self::assertIsString( $meta );
		self::assertIsString( $screen );
		self::assertStringNotContainsString( '.wp-list-table', $js );
		self::assertStringContainsString( 'reorder.enhance(reorderRoot', $js );
		self::assertStringNotContainsString( 'jQuery', $js );
		self::assertStringNotContainsString( 'pre_get_posts', $meta );
		self::assertStringNotContainsString( 'posts_clauses', $meta );
		self::assertStringNotContainsString( 'sortable', $meta );
		self::assertStringNotContainsString( 'manageedit-', $screen );
		self::assertStringNotContainsString( 'columnshidden', $screen );
	}
}
