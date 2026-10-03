<?php
declare(strict_types=1);

use CoreBlueprint\Core\Ajax\Handlers\Permissions as PermissionsActions;
use CoreBlueprint\Core\Ajax\Request;
use CoreBlueprint\Core\ContentModels\Admin\MetaBoxes as ContentModelMetaBoxes;
use CoreBlueprint\Core\ContentModels\Repository as ContentModelRepository;
use CoreBlueprint\Core\ContentModels\State as ContentModelsState;
use CoreBlueprint\Core\Integrity\State as IntegrityState;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\MediaFormats\Admin\Actions as MediaFormatsActions;
use CoreBlueprint\Core\MediaFormats\Settings as MediaFormatsSettings;
use CoreBlueprint\Core\MediaFormats\State as MediaFormatsState;
use CoreBlueprint\Core\Permissions\PrivilegedAccessGuard;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Permissions\Roles;
use CoreBlueprint\Core\Settings;

final class CB_C1_Test_Termination extends RuntimeException {
    /** @var mixed */
    public $wp_message;

    /** @var mixed */
    public $wp_title;

    /** @var mixed */
    public $wp_args;

    public int $http_response = 0;

    public function __construct( $message, $title, $args ) {
        parent::__construct( '__CB_C1_TEST_TERMINATION__' );
        $this->wp_message = $message;
        $this->wp_title   = $title;
        $this->wp_args    = $args;
    }
}

final class CB_C1_Test_Redirect extends RuntimeException {
    public string $location;

    public function __construct( string $location ) {
        parent::__construct( '__CB_C1_TEST_REDIRECT__' );
        $this->location = $location;
    }
}

final class CB_Base_Privileged_Request_Contract_Test extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        unset( $_SERVER['HTTP_X_WP_NONCE'] );

        Roles::ensure_operator_role();
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        unset( $_SERVER['HTTP_X_WP_NONCE'] );

        global $wp_rest_server;
        $wp_rest_server = null;

        parent::tear_down();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_c1_typed_boolean_request_rejects_invalid_shapes_and_values(): void {
        foreach ( [ [ '1' ], 'not-a-boolean' ] as $value ) {
            $this->set_post( [ 'enabled' => $value ] );
            $result = $this->capture_termination( static fn() => Request::bool( 'enabled' ), true );
            self::assertSame( 400, $this->response_code( $result['termination'] ) );
            self::assertFalse( $this->json_payload( $result['output'] )['success'] );
        }

        $this->set_post( [ 'enabled' => '1' ] );
        self::assertTrue( Request::bool( 'enabled' ) );

        $this->set_post( [ 'enabled' => '0' ] );
        self::assertFalse( Request::bool( 'enabled' ) );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_c1_ajax_permissions_request_contract(): void {
        PermissionsActions::init();
        self::assertNotFalse( has_action( 'wp_ajax_cb_core_save_permission_hide', [ PermissionsActions::class, 'save_hide' ] ) );
        self::assertFalse( has_action( 'wp_ajax_nopriv_cb_core_save_permission_hide' ), 'Privileged AJAX action exposed a nopriv route.' );

        $trusted    = $this->create_operator( true );
        $unapproved = $this->create_operator( false );
        $subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $before     = $this->permissions_hide_value();
        $audit      = $this->audit_count( 'permissions.hide_toggled' );

        self::assertFalse( headers_sent(), 'AJAX status contract harness requires headers to remain unsent before the first request.' );

        wp_set_current_user( 0 );
        $this->set_post( [
            'nonce'   => wp_create_nonce( 'cb_core_admin' ),
            'enabled' => $before ? '0' : '1',
        ] );
        $result = $this->capture_termination( static fn() => do_action( 'wp_ajax_cb_core_save_permission_hide' ), true );
        self::assertSame( 403, $this->response_code( $result['termination'] ) );
        $this->assert_no_permissions_mutation( $before, $audit, 'Unauthenticated AJAX request' );

        wp_set_current_user( $subscriber );
        $this->set_post( [
            'nonce'   => wp_create_nonce( 'cb_core_admin' ),
            'enabled' => $before ? '0' : '1',
        ] );
        $result = $this->capture_termination( static fn() => do_action( 'wp_ajax_cb_core_save_permission_hide' ), true );
        self::assertSame( 403, $this->response_code( $result['termination'] ) );
        $this->assert_no_permissions_mutation( $before, $audit, 'Wrong-capability AJAX request' );

        wp_set_current_user( (int) $trusted->ID );
        foreach ( [ null, 'invalid-c1-nonce' ] as $nonce ) {
            $post = [ 'enabled' => $before ? '0' : '1' ];
            if ( null !== $nonce ) {
                $post['nonce'] = $nonce;
            }
            $this->set_post( $post );
            $result = $this->capture_termination( static fn() => do_action( 'wp_ajax_cb_core_save_permission_hide' ), true );
            self::assertSame( 403, $this->response_code( $result['termination'] ) );
            $this->assert_no_permissions_mutation( $before, $audit, 'Invalid-nonce AJAX request' );
        }

        wp_set_current_user( (int) $unapproved->ID );
        $this->set_post( [
            'nonce'   => wp_create_nonce( 'cb_core_admin' ),
            'enabled' => $before ? '0' : '1',
        ] );
        $result = $this->capture_termination( static fn() => do_action( 'wp_ajax_cb_core_save_permission_hide' ), true );
        self::assertSame( 403, $this->response_code( $result['termination'] ) );
        $this->assert_no_permissions_mutation( $before, $audit, 'Unapproved privileged identity' );

        wp_set_current_user( (int) $trusted->ID );
        $this->set_post( [
            'nonce'   => wp_create_nonce( 'cb_core_admin' ),
            'enabled' => 'not-a-boolean',
        ] );
        $result = $this->capture_termination( static fn() => do_action( 'wp_ajax_cb_core_save_permission_hide' ), true );
        self::assertSame( 400, $this->response_code( $result['termination'] ) );
        self::assertFalse( $this->json_payload( $result['output'] )['success'] );
        $this->assert_no_permissions_mutation( $before, $audit, 'Malformed AJAX input' );

        $target = ! $before;
        $this->set_post( [
            'nonce'   => wp_create_nonce( 'cb_core_admin' ),
            'enabled' => $target ? '1' : '0',
        ] );
        $result  = $this->capture_termination( static fn() => do_action( 'wp_ajax_cb_core_save_permission_hide' ), true );
        $payload = $this->json_payload( $result['output'] );
        self::assertTrue( $payload['success'] );
        self::assertSame( $target, $this->permissions_hide_value(), 'Authorized AJAX request did not persist the setting.' );
        self::assertSame( $audit + 1, $this->audit_count( 'permissions.hide_toggled' ), 'Authorized AJAX request did not emit exactly one success audit.' );
    }

    public function test_c1_rest_policy_request_contract(): void {
        $trusted    = $this->create_operator( true );
        $unapproved = $this->create_operator( false );
        $subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $route      = '/core-blueprint/v1/integrity/admin/locale/mode';

        wp_set_current_user( 0 );
        $response = $this->dispatch_rest( 'PUT', $route, [ 'mode' => 'override', 'override' => 'nl_NL' ] );
        self::assertSame( 401, $response->get_status(), 'Unauthenticated REST mutation was not denied.' );

        wp_set_current_user( $subscriber );
        $response = $this->dispatch_rest(
            'PUT',
            $route,
            [ 'mode' => 'override', 'override' => 'nl_NL' ],
            true,
            wp_create_nonce( 'wp_rest' )
        );
        self::assertSame( 403, $response->get_status(), 'Wrong-capability REST mutation was not denied.' );

        wp_set_current_user( (int) $unapproved->ID );
        $response = $this->dispatch_rest(
            'PUT',
            $route,
            [ 'mode' => 'override', 'override' => 'nl_NL' ],
            true,
            wp_create_nonce( 'wp_rest' )
        );
        self::assertSame( 403, $response->get_status(), 'Unapproved privileged identity reached Scanner policy mutation.' );

        wp_set_current_user( (int) $trusted->ID );
        $before = (array) ( Settings::get()['integrity'] ?? [] );
        $audit  = $this->audit_count( 'integrity_distribution_locale_changed' );
        $response = $this->dispatch_rest(
            'PUT',
            $route,
            [ 'mode' => 'override', 'override' => 'nl_NL' ],
            true,
            null
        );
        self::assertSame( 401, $response->get_status(), 'Missing REST cookie nonce did not de-authenticate the request.' );
        self::assertSame( $before, (array) ( Settings::get()['integrity'] ?? [] ), 'Missing REST cookie nonce mutated Scanner settings.' );
        self::assertSame( $audit, $this->audit_count( 'integrity_distribution_locale_changed' ), 'Missing REST cookie nonce emitted a success audit.' );

        wp_set_current_user( (int) $trusted->ID );
        $response = $this->dispatch_rest(
            'PUT',
            $route,
            [ 'mode' => 'override', 'override' => 'nl_NL' ],
            true,
            'invalid-c1-rest-nonce'
        );
        self::assertSame( 403, $response->get_status(), 'Invalid REST cookie nonce was not rejected.' );
        self::assertSame( 'rest_cookie_invalid_nonce', (string) ( $response->get_data()['code'] ?? '' ) );
        self::assertSame( $before, (array) ( Settings::get()['integrity'] ?? [] ), 'Invalid REST cookie nonce mutated Scanner settings.' );
        self::assertSame( $audit, $this->audit_count( 'integrity_distribution_locale_changed' ), 'Invalid REST cookie nonce emitted a success audit.' );

        wp_set_current_user( (int) $trusted->ID );
        $response = $this->dispatch_rest(
            'PUT',
            $route,
            [ 'mode' => 'not-a-mode', 'override' => 'nl_NL' ],
            true,
            wp_create_nonce( 'wp_rest' )
        );
        self::assertSame( 400, $response->get_status() );
        self::assertSame( 'cb_integrity_invalid_locale_mode', (string) ( $response->get_data()['code'] ?? '' ) );
        self::assertSame( $before, (array) ( Settings::get()['integrity'] ?? [] ), 'Malformed REST input mutated Scanner settings.' );
        self::assertSame( $audit, $this->audit_count( 'integrity_distribution_locale_changed' ), 'Malformed REST input emitted a success audit.' );

        if ( ! IntegrityState::is_enabled() ) {
            IntegrityState::set_enabled( true, 'c1-rest-precondition' );
        }
        IntegrityState::set_enabled( false, 'c1-rest-disabled' );
        $disabled_settings = (array) ( Settings::get()['integrity'] ?? [] );
        $disabled_audit    = $this->audit_count( 'integrity_distribution_locale_detected' );
        wp_set_current_user( (int) $trusted->ID );
        $response = $this->dispatch_rest(
            'POST',
            '/core-blueprint/v1/integrity/admin/locale/redetect',
            [],
            true,
            wp_create_nonce( 'wp_rest' )
        );
        self::assertSame( 403, $response->get_status(), 'Disabled Scanner accepted a functional REST mutation.' );
        self::assertSame( 'cb_integrity_subsystem_disabled', (string) ( $response->get_data()['code'] ?? '' ) );
        self::assertSame( $disabled_settings, (array) ( Settings::get()['integrity'] ?? [] ), 'Disabled Scanner REST request mutated settings.' );
        self::assertSame( $disabled_audit, $this->audit_count( 'integrity_distribution_locale_detected' ), 'Disabled Scanner REST request emitted a success audit.' );
        IntegrityState::set_enabled( true, 'c1-rest-restore' );

        $integrity = (array) ( Settings::get()['integrity'] ?? [] );
        $current_override = (string) ( $integrity['distribution_locale_override'] ?? '' );
        $target_override  = 'nl_NL' === $current_override ? 'en_US' : 'nl_NL';
        $success_audit    = $this->audit_count( 'integrity_distribution_locale_changed' );
        wp_set_current_user( (int) $trusted->ID );
        $response = $this->dispatch_rest(
            'PUT',
            $route,
            [
                'mode'     => 'override',
                'override' => $target_override,
            ],
            true,
            wp_create_nonce( 'wp_rest' )
        );
        self::assertSame( 200, $response->get_status(), 'Authorized REST policy mutation did not succeed.' );
        $stored = (array) ( Settings::get()['integrity'] ?? [] );
        self::assertSame( 'override', (string) ( $stored['distribution_locale_mode'] ?? '' ) );
        self::assertSame( $target_override, (string) ( $stored['distribution_locale_override'] ?? '' ) );
        self::assertSame( $success_audit + 1, $this->audit_count( 'integrity_distribution_locale_changed' ), 'Authorized REST mutation did not emit exactly one success audit.' );
    }

    public function test_c1_admin_post_media_formats_request_contract(): void {
        MediaFormatsActions::boot();
        self::assertNotFalse( has_action( 'admin_post_cb_core_media_formats_save', [ MediaFormatsActions::class, 'save' ] ) );
        self::assertFalse( has_action( 'admin_post_nopriv_cb_core_media_formats_save' ), 'Privileged admin-post action exposed a nopriv route.' );

        $admin_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $admin      = get_userdata( $admin_id );
        self::assertInstanceOf( WP_User::class, $admin );
        self::assertTrue( PrivilegedAccessRegistry::approve( $admin, 0, 'c1-media-formats-fixture' ), 'Could not approve C1 Media Formats Administrator fixture.' );
        $admin      = get_userdata( $admin_id );
        self::assertInstanceOf( WP_User::class, $admin );
        $subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        if ( ! MediaFormatsState::is_enabled() ) {
            MediaFormatsState::set_enabled( true, 'c1-admin-post-precondition' );
        }
        MediaFormatsSettings::save( [
            'svg_uploads'   => false,
            'webp_uploads'  => false,
            'avif_uploads'  => false,
            'jxl_uploads'   => false,
            'heic_imports'  => false,
            'output_format' => 'original',
        ], 'c1-admin-post-setup' );
        $before = MediaFormatsSettings::all();
        $audit  = $this->audit_count( 'media_formats_settings_changed' );

        wp_set_current_user( 0 );
        $this->set_post( [ '_wpnonce' => wp_create_nonce( 'cb_core_media_formats_save' ) ] );
        $result = $this->capture_termination( static fn() => do_action( 'admin_post_cb_core_media_formats_save' ) );
        self::assertSame( 403, $this->response_code( $result['termination'] ) );
        $this->assert_no_media_formats_mutation( $before, $audit, 'Unauthenticated admin-post request' );

        wp_set_current_user( $subscriber );
        $this->set_post( [ '_wpnonce' => wp_create_nonce( 'cb_core_media_formats_save' ) ] );
        $result = $this->capture_termination( static fn() => do_action( 'admin_post_cb_core_media_formats_save' ) );
        self::assertSame( 403, $this->response_code( $result['termination'] ) );
        $this->assert_no_media_formats_mutation( $before, $audit, 'Wrong-capability admin-post request' );

        wp_set_current_user( (int) $admin->ID );
        self::assertTrue( current_user_can( 'manage_options' ), 'Approved Media Formats Administrator fixture does not pass the authorization gate.' );
        foreach ( [ null, 'invalid-c1-nonce' ] as $nonce ) {
            $post = [];
            if ( null !== $nonce ) {
                $post['_wpnonce'] = $nonce;
            }
            $this->set_post( $post );
            $result = $this->capture_termination( static fn() => do_action( 'admin_post_cb_core_media_formats_save' ) );
            self::assertSame( 403, $this->response_code( $result['termination'] ) );
            $this->assert_no_media_formats_mutation( $before, $audit, 'Invalid-nonce admin-post request' );
        }

        MediaFormatsState::set_enabled( false, 'c1-admin-post-disabled' );
        $disabled = MediaFormatsSettings::all();
        $disabled_audit = $this->audit_count( 'media_formats_settings_changed' );
        $this->set_post( [ '_wpnonce' => wp_create_nonce( 'cb_core_media_formats_save' ) ] );
        $result = $this->capture_termination( static fn() => do_action( 'admin_post_cb_core_media_formats_save' ) );
        self::assertSame( 409, $this->response_code( $result['termination'] ) );
        $this->assert_no_media_formats_mutation( $disabled, $disabled_audit, 'Disabled-module admin-post request' );

        MediaFormatsState::set_enabled( true, 'c1-admin-post-restore' );
        $success_before = MediaFormatsSettings::all();
        $target_jxl     = ! $success_before['jxl_uploads'];
        $success_audit  = $this->audit_count( 'media_formats_settings_changed' );
        $post = [
            '_wpnonce'      => wp_create_nonce( 'cb_core_media_formats_save' ),
            'output_format' => (string) $success_before['output_format'],
        ];
        if ( $target_jxl ) {
            $post['jxl_uploads'] = '1';
        }
        $this->set_post( $post );
        $redirect = $this->capture_redirect( static fn() => do_action( 'admin_post_cb_core_media_formats_save' ) );
        self::assertStringContainsString( 'media_formats_result=saved', $redirect->location );
        self::assertSame( $target_jxl, MediaFormatsSettings::all()['jxl_uploads'], 'Authorized admin-post request did not persist the setting.' );
        self::assertSame( $success_audit + 1, $this->audit_count( 'media_formats_settings_changed' ), 'Authorized admin-post request did not emit exactly one success audit.' );
    }

    public function test_c1_content_models_enforces_post_object_capability(): void {
        if ( ! ContentModelsState::is_enabled() ) {
            ContentModelsState::set_enabled( true, 'c1-object-cap-precondition' );
        }

        $group = ContentModelRepository::save_field_group( [
            'id'              => 'c1_contract_group',
            'title'           => 'C1 Contract Group',
            'post_types'      => [ 'post' ],
            'option_pages'    => [],
            'term_taxonomies' => [],
            'user_enabled'    => false,
            'user_roles'      => [],
            'context'         => 'normal',
            'priority'        => 'default',
        ] );
        ContentModelRepository::save_field( (string) $group['id'], [
            'id'    => 'c1_contract_field',
            'label' => 'C1 Contract Value',
            'name'  => 'c1_contract_value',
            'type'  => 'text',
        ] );

        $owner = self::factory()->user->create( [ 'role' => 'author' ] );
        $actor = self::factory()->user->create( [ 'role' => 'author' ] );
        $foreign_post_id = self::factory()->post->create( [
            'post_author' => $owner,
            'post_status' => 'draft',
        ] );
        $own_post_id = self::factory()->post->create( [
            'post_author' => $actor,
            'post_status' => 'draft',
        ] );
        update_post_meta( $foreign_post_id, 'c1_contract_value', 'protected' );

        wp_set_current_user( $actor );
        self::assertFalse( current_user_can( 'edit_post', $foreign_post_id ), 'Object-capability denial precondition is invalid.' );
        $this->set_post( [
            'cb_cm_nonce_c1_contract_group' => wp_create_nonce( 'cb_cm_save_fields_c1_contract_group' ),
            'cb_cm_fields'                  => [ 'c1_contract_value' => 'blocked-change' ],
            'cb_cm_fields_present'          => [ 'c1_contract_value' => '1' ],
        ] );
        ContentModelMetaBoxes::save( $foreign_post_id, get_post( $foreign_post_id ) );
        self::assertSame( 'protected', get_post_meta( $foreign_post_id, 'c1_contract_value', true ), 'Missing object meta-cap still mutated foreign post meta.' );

        self::assertTrue( current_user_can( 'edit_post', $own_post_id ), 'Object-capability happy-path precondition is invalid.' );
        $this->set_post( [
            'cb_cm_nonce_c1_contract_group' => wp_create_nonce( 'cb_cm_save_fields_c1_contract_group' ),
            'cb_cm_fields'                  => [ 'c1_contract_value' => 'allowed-change' ],
            'cb_cm_fields_present'          => [ 'c1_contract_value' => '1' ],
        ] );
        ContentModelMetaBoxes::save( $own_post_id, get_post( $own_post_id ) );
        self::assertSame( 'allowed-change', get_post_meta( $own_post_id, 'c1_contract_value', true ), 'Authorized object mutation did not persist.' );
    }

    private function create_operator( bool $approved ): WP_User {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $user = get_userdata( $user_id );
        self::assertInstanceOf( WP_User::class, $user );

        PrivilegedAccessGuard::trusted_mutation( static function () use ( $user ): void {
            $user->add_role( Roles::OPERATOR_ROLE );
        } );
        $user = get_userdata( $user_id );
        self::assertInstanceOf( WP_User::class, $user );

        if ( $approved ) {
            self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'c1-test-fixture' ), 'Could not approve C1 Operator fixture.' );
            $user = get_userdata( $user_id );
            self::assertInstanceOf( WP_User::class, $user );
            self::assertTrue( PrivilegedAccessGuard::is_trusted_operator( $user ), 'Approved C1 Operator fixture is not trusted.' );
        }

        return $user;
    }

    private function permissions_hide_value(): bool {
        return ! empty( Settings::get()['permissions']['hide_from_admins'] );
    }

    private function assert_no_permissions_mutation( bool $before, int $audit, string $context ): void {
        self::assertSame( $before, $this->permissions_hide_value(), $context . ' mutated permission settings.' );
        self::assertSame( $audit, $this->audit_count( 'permissions.hide_toggled' ), $context . ' emitted a success audit.' );
    }

    private function assert_no_media_formats_mutation( array $before, int $audit, string $context ): void {
        self::assertSame( $before, MediaFormatsSettings::all(), $context . ' mutated Media Formats settings.' );
        self::assertSame( $audit, $this->audit_count( 'media_formats_settings_changed' ), $context . ' emitted a success audit.' );
    }

    private function audit_count( string $event_type ): int {
        $result = AuditLog::query( [
            'event_type' => AuditLog::normalize_event_type( $event_type ),
            'per_page'   => 1,
        ] );
        return (int) $result['total'];
    }

    private function set_post( array $post ): void {
        $_POST = $post;
        $_REQUEST = $post;
    }

    /** @return array{termination:CB_C1_Test_Termination,output:string} */
    private function capture_termination( callable $callback, bool $ajax = false ): array {
        $die_handler = static function ( $message = '', $title = '', $args = [] ): void {
            throw new CB_C1_Test_Termination( $message, $title, $args );
        };
        $handler_filter = static fn() => $die_handler;
        $ajax_filter    = static fn() => true;
        $status_code    = 0;
        $status_filter  = static function ( $status_header, $code, $description, $protocol ) use ( &$status_code ) {
            unset( $description, $protocol );
            $status_code = (int) $code;
            return $status_header;
        };

        add_filter( 'wp_die_handler', $handler_filter, PHP_INT_MAX );
        add_filter( 'wp_die_ajax_handler', $handler_filter, PHP_INT_MAX );
        add_filter( 'status_header', $status_filter, PHP_INT_MAX, 4 );
        if ( $ajax ) {
            add_filter( 'wp_doing_ajax', $ajax_filter, PHP_INT_MAX );
        }

        $level = ob_get_level();
        ob_start();
        try {
            $callback();
            self::fail( 'Expected WordPress handler to terminate the request.' );
        } catch ( CB_C1_Test_Termination $termination ) {
            $output = (string) ob_get_clean();
            $args = is_array( $termination->wp_args ) ? $termination->wp_args : [];
            $termination->http_response = $status_code > 0 ? $status_code : (int) ( $args['response'] ?? 0 );
            return [ 'termination' => $termination, 'output' => $output ];
        } finally {
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
            remove_filter( 'wp_die_handler', $handler_filter, PHP_INT_MAX );
            remove_filter( 'wp_die_ajax_handler', $handler_filter, PHP_INT_MAX );
            remove_filter( 'status_header', $status_filter, PHP_INT_MAX );
            if ( $ajax ) {
                remove_filter( 'wp_doing_ajax', $ajax_filter, PHP_INT_MAX );
            }
        }
    }

    private function capture_redirect( callable $callback ): CB_C1_Test_Redirect {
        $redirect_filter = static function ( $location, $status ) {
            unset( $status );
            throw new CB_C1_Test_Redirect( (string) $location );
        };
        add_filter( 'wp_redirect', $redirect_filter, PHP_INT_MAX, 2 );
        try {
            $callback();
            self::fail( 'Expected WordPress handler to redirect.' );
        } catch ( CB_C1_Test_Redirect $redirect ) {
            return $redirect;
        } finally {
            remove_filter( 'wp_redirect', $redirect_filter, PHP_INT_MAX );
        }
    }

    private function response_code( CB_C1_Test_Termination $termination ): int {
        return $termination->http_response;
    }

    /** @return array<string,mixed> */
    private function json_payload( string $output ): array {
        $payload = json_decode( trim( $output ), true );
        self::assertIsArray( $payload, 'AJAX response was not valid JSON.' );
        return $payload;
    }

    private function dispatch_rest(
        string $method,
        string $route,
        array $params = [],
        bool $cookie_auth = false,
        ?string $nonce = null
    ): WP_REST_Response {
        global $wp_rest_server, $wp_rest_auth_cookie;

        $had_cookie_auth       = array_key_exists( 'wp_rest_auth_cookie', $GLOBALS );
        $previous_cookie_auth  = $wp_rest_auth_cookie ?? null;
        $had_server_nonce      = array_key_exists( 'HTTP_X_WP_NONCE', $_SERVER );
        $previous_server_nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? null;
        $had_request_nonce     = array_key_exists( '_wpnonce', $_REQUEST );
        $previous_request_nonce = $_REQUEST['_wpnonce'] ?? null;

        $wp_rest_server = new Spy_REST_Server();
        do_action( 'rest_api_init' );
        self::assertArrayHasKey( $route, $wp_rest_server->get_routes(), 'Expected privileged REST route is not registered.' );

        $request = new WP_REST_Request( $method, $route );
        foreach ( $params as $key => $value ) {
            $request->set_param( (string) $key, $value );
        }

        try {
            $wp_rest_auth_cookie = $cookie_auth;
            unset( $_REQUEST['_wpnonce'] );
            if ( null === $nonce ) {
                unset( $_SERVER['HTTP_X_WP_NONCE'] );
            } else {
                $_SERVER['HTTP_X_WP_NONCE'] = $nonce;
            }

            $authentication = $wp_rest_server->check_authentication();
            if ( is_wp_error( $authentication ) ) {
                return rest_convert_error_to_response( $authentication );
            }

            return $wp_rest_server->dispatch( $request );
        } finally {
            if ( $had_cookie_auth ) {
                $wp_rest_auth_cookie = $previous_cookie_auth;
            } else {
                unset( $GLOBALS['wp_rest_auth_cookie'] );
            }

            if ( $had_server_nonce ) {
                $_SERVER['HTTP_X_WP_NONCE'] = $previous_server_nonce;
            } else {
                unset( $_SERVER['HTTP_X_WP_NONCE'] );
            }

            if ( $had_request_nonce ) {
                $_REQUEST['_wpnonce'] = $previous_request_nonce;
            } else {
                unset( $_REQUEST['_wpnonce'] );
            }
        }
    }
}
