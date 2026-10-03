<?php
declare(strict_types=1);

use CoreBlueprint\Core\ContentModels\Admin\Actions;
use CoreBlueprint\Core\ContentModels\Admin\MetaBoxes;
use CoreBlueprint\Core\ContentModels\Admin\OptionPages;
use CoreBlueprint\Core\ContentModels\Admin\TermMeta;
use CoreBlueprint\Core\ContentModels\Admin\Transfer;
use CoreBlueprint\Core\ContentModels\Admin\UserMeta;
use CoreBlueprint\Core\ContentModels\Bootstrap as ContentModelsBootstrap;
use CoreBlueprint\Core\ContentModels\Importers\NativeWordPress\Bootstrap as NativeImporter;
use CoreBlueprint\Core\RequestContext;

final class CB_Base_Content_Models_Bootstrap_Boundary_Test extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        update_option( 'cb_core_content_models_enabled', '1', false );
        unset( $_SERVER['SCRIPT_NAME'], $_SERVER['PHP_SELF'], $_SERVER['SCRIPT_FILENAME'] );
        unset( $GLOBALS['current_screen'] );
        $this->clear_admin_surface_hooks();
    }

    public function tear_down(): void {
        unset( $GLOBALS['current_screen'] );
        unset( $_SERVER['SCRIPT_NAME'], $_SERVER['PHP_SELF'], $_SERVER['SCRIPT_FILENAME'] );
        parent::tear_down();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_frontend_does_not_register_content_models_admin_surfaces(): void {
        self::assertFalse( is_admin(), 'Frontend harness unexpectedly reports wp-admin.' );
        self::assertFalse( RequestContext::is_admin_screen() );
        self::assertFalse( RequestContext::is_admin_post() );
        self::assertFalse( RequestContext::is_ajax() );
        self::assertFalse( RequestContext::is_cli() );

        ContentModelsBootstrap::boot();

        $this->assert_no_admin_surface_hooks();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_normal_admin_screen_registers_screen_surfaces_only(): void {
        set_current_screen( 'dashboard' );
        self::assertTrue( RequestContext::is_admin_screen() );
        self::assertFalse( RequestContext::is_admin_post() );
        self::assertFalse( RequestContext::is_ajax() );
        self::assertFalse( RequestContext::is_cli() );

        ContentModelsBootstrap::boot();

        self::assertNotFalse( has_action( 'add_meta_boxes', [ MetaBoxes::class, 'register_boxes' ] ) );
        self::assertNotFalse( has_action( 'admin_menu', [ OptionPages::class, 'register_menus' ] ) );
        self::assertNotFalse( has_action( 'created_term', [ TermMeta::class, 'save' ] ) );
        self::assertNotFalse( has_action( 'show_user_profile', [ UserMeta::class, 'render' ] ) );

        self::assertFalse( has_action( 'admin_post_cb_core_content_models_save_post_type', [ Actions::class, 'save_post_type' ] ) );
        self::assertFalse( has_action( 'wp_ajax_cb_core_content_models_quick_save_field', [ Actions::class, 'quick_save_field' ] ) );
        self::assertFalse( has_action( 'admin_post_cb_core_content_models_export_schema', [ Transfer::class, 'export' ] ) );
        self::assertFalse( has_action( 'admin_post_cb_core_content_models_native_discover', [ NativeImporter::class, 'discover' ] ) );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_admin_post_registers_write_boundaries_without_screen_surfaces(): void {
        $_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-post.php';
        self::assertTrue( RequestContext::is_admin_post() );
        self::assertFalse( RequestContext::is_admin_screen() );
        self::assertFalse( RequestContext::is_ajax() );
        self::assertFalse( RequestContext::is_cli() );

        ContentModelsBootstrap::boot();

        self::assertNotFalse( has_action( 'admin_post_cb_core_content_models_save_post_type', [ Actions::class, 'save_post_type' ] ) );
        self::assertNotFalse( has_action( 'admin_post_cb_core_content_models_save_option_values', [ OptionPages::class, 'save_values' ] ) );
        self::assertNotFalse( has_action( 'admin_post_cb_core_content_models_export_schema', [ Transfer::class, 'export' ] ) );
        self::assertNotFalse( has_action( 'admin_post_cb_core_content_models_native_discover', [ NativeImporter::class, 'discover' ] ) );

        self::assertFalse( has_action( 'add_meta_boxes', [ MetaBoxes::class, 'register_boxes' ] ) );
        self::assertFalse( has_action( 'created_term', [ TermMeta::class, 'save' ] ) );
        self::assertFalse( has_action( 'show_user_profile', [ UserMeta::class, 'render' ] ) );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_ajax_registers_actions_without_screen_or_transfer_surfaces(): void {
        if ( ! defined( 'DOING_AJAX' ) ) {
            define( 'DOING_AJAX', true );
        }
        $_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-ajax.php';
        set_current_screen( 'dashboard' );
        self::assertTrue( RequestContext::is_ajax() );
        self::assertFalse( RequestContext::is_admin_screen() );
        self::assertFalse( RequestContext::is_admin_post() );
        self::assertFalse( RequestContext::is_cli() );

        ContentModelsBootstrap::boot();

        self::assertNotFalse( has_action( 'wp_ajax_cb_core_content_models_quick_save_field', [ Actions::class, 'quick_save_field' ] ) );
        self::assertFalse( has_action( 'add_meta_boxes', [ MetaBoxes::class, 'register_boxes' ] ) );
        self::assertFalse( has_action( 'admin_menu', [ OptionPages::class, 'register_menus' ] ) );
        self::assertFalse( has_action( 'created_term', [ TermMeta::class, 'save' ] ) );
        self::assertFalse( has_action( 'show_user_profile', [ UserMeta::class, 'render' ] ) );
        self::assertFalse( has_action( 'admin_post_cb_core_content_models_export_schema', [ Transfer::class, 'export' ] ) );
        self::assertFalse( has_action( 'admin_post_cb_core_content_models_native_discover', [ NativeImporter::class, 'discover' ] ) );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_cli_does_not_register_content_models_admin_surfaces_even_with_admin_screen(): void {
        if ( ! defined( 'WP_CLI' ) ) {
            define( 'WP_CLI', true );
        }
        set_current_screen( 'dashboard' );
        self::assertTrue( RequestContext::is_cli() );
        self::assertFalse( RequestContext::is_admin_screen() );
        self::assertFalse( RequestContext::is_admin_post() );
        self::assertFalse( RequestContext::is_ajax() );

        ContentModelsBootstrap::boot();

        $this->assert_no_admin_surface_hooks();
    }

    private function clear_admin_surface_hooks(): void {
        $this->remove_hook( 'add_meta_boxes', [ MetaBoxes::class, 'register_boxes' ] );
        $this->remove_hook( 'admin_menu', [ OptionPages::class, 'register_menus' ] );
        $this->remove_hook( 'created_term', [ TermMeta::class, 'save' ] );
        $this->remove_hook( 'show_user_profile', [ UserMeta::class, 'render' ] );
        $this->remove_hook( 'admin_post_cb_core_content_models_save_post_type', [ Actions::class, 'save_post_type' ] );
        $this->remove_hook( 'wp_ajax_cb_core_content_models_quick_save_field', [ Actions::class, 'quick_save_field' ] );
        $this->remove_hook( 'admin_post_cb_core_content_models_export_schema', [ Transfer::class, 'export' ] );
        $this->remove_hook( 'admin_post_cb_core_content_models_native_discover', [ NativeImporter::class, 'discover' ] );
    }

    /** @param callable|array{0:class-string,1:string} $callback */
    private function remove_hook( string $hook, $callback ): void {
        $priority = has_action( $hook, $callback );
        if ( false !== $priority ) {
            remove_action( $hook, $callback, $priority );
        }
        self::assertFalse( has_action( $hook, $callback ), 'Test harness could not clear baseline hook: ' . $hook );
    }

    private function assert_no_admin_surface_hooks(): void {
        self::assertFalse( has_action( 'add_meta_boxes', [ MetaBoxes::class, 'register_boxes' ] ) );
        self::assertFalse( has_action( 'admin_menu', [ OptionPages::class, 'register_menus' ] ) );
        self::assertFalse( has_action( 'created_term', [ TermMeta::class, 'save' ] ) );
        self::assertFalse( has_action( 'show_user_profile', [ UserMeta::class, 'render' ] ) );
        self::assertFalse( has_action( 'admin_post_cb_core_content_models_save_post_type', [ Actions::class, 'save_post_type' ] ) );
        self::assertFalse( has_action( 'wp_ajax_cb_core_content_models_quick_save_field', [ Actions::class, 'quick_save_field' ] ) );
        self::assertFalse( has_action( 'admin_post_cb_core_content_models_export_schema', [ Transfer::class, 'export' ] ) );
        self::assertFalse( has_action( 'admin_post_cb_core_content_models_native_discover', [ NativeImporter::class, 'discover' ] ) );
    }
}
