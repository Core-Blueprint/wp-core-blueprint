<?php
declare(strict_types=1);

/**
 * MAIL-EXT-01B: PHP execution of native Mail resource authorization and
 * grouped selector output. No WordPress, DB, network or Save mutations.
 */
namespace CoreBlueprint\Core\Admin {
    class PageBase {}
}
namespace CoreBlueprint\Core\Mail {
    final class DesignerState {
        public static bool $enabled = true;
        public static function is_enabled(): bool { return self::$enabled; }
    }
}
namespace CoreBlueprint\Core\Design\Profile\Reports {
    final class PlatformAdapter { public const PROFILE = 'base/reports'; }
}
namespace {
    defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );

    use CoreBlueprint\Core\Design\Document\Repository;
    use CoreBlueprint\Core\Design\Editor\PlatformTransport;
    use CoreBlueprint\Core\Design\Profile\Mail\Contract;
    use CoreBlueprint\Core\Mail\Admin\Page;
    use CoreBlueprint\Core\Mail\Designer\TemplateRegistry;
    use CoreBlueprint\Core\Mail\DesignerState;

    function __( string $text, string $domain = '' ): string { return $text; }
    function sanitize_text_field( string $text ): string { return trim( strip_tags( $text ) ); }
    function esc_attr( string $text ): string {
        return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }
    function esc_html( string $text ): string { return esc_attr( $text ); }
    function selected( mixed $value, mixed $current, bool $echo = true ): string {
        $html = $value === $current ? ' selected="selected"' : '';
        if ( $echo ) echo $html;
        return $html;
    }
    function do_action( string $hook ): void {
        if ( 'core_blueprint_register_mail_templates' === $hook && $GLOBALS['cb_provider_active'] ) {
            $GLOBALS['cb_provider_success'] = TemplateRegistry::register( $GLOBALS['cb_provider_template'] );
        }
    }
    function current_user_can( string $cap ): bool {
        return 'manage_options' === $cap && $GLOBALS['cb_can_manage'];
    }
    function wp_verify_nonce( string $nonce, string $action ): bool {
        return 'good' === $nonce && 'wp_rest' === $action;
    }
    function get_current_user_id(): int { return $GLOBALS['cb_logged_in'] ? 4 : 0; }
    function is_user_logged_in(): bool { return $GLOBALS['cb_logged_in']; }

    class WP_REST_Request {
        public function __construct( private string $nonce ) {}
        public function get_header( string $key ): string { return $this->nonce; }
    }

    function cb_ext_assert( bool $ok, string $name ): void {
        if ( ! $ok ) throw new \RuntimeException( 'MAIL-EXT-01B: ' . $name );
    }

    $root = dirname( __DIR__ );
    foreach ( [
        'src/Design/Kernel/Diagnostic.php',
        'src/Design/Kernel/Diagnostics.php',
        'src/Design/Profile/Mail/Contract.php',
        'src/Design/Profile/Mail/Validator.php',
        'src/Design/Profile/Mail/PlatformAdapter.php',
        'src/Design/Document/Repository.php',
        'src/Design/Editor/PlatformTransport.php',
        'src/Mail/Designer/TemplateRegistry.php',
        'src/Mail/Designer/WordPressTemplates.php',
        'src/Mail/Admin/Page.php',
    ] as $file ) require_once $root . '/' . $file;

    $document = Contract::default_document();
    $document['fields'] = [
        'subject' => 'Booking confirmed for {{booking.customer_name}}',
        'preheader' => 'A booking reminder',
    ];
    $GLOBALS['cb_provider_template'] = [
        'id' => 'bookings.customer-confirmed',
        'provider' => 'bookings',
        'group' => 'bookings',
        'label' => 'Booking & confirmation',
        'document' => $document,
    ];
    $GLOBALS['cb_provider_active'] = true;
    $GLOBALS['cb_provider_success'] = false;
    $GLOBALS['cb_can_manage'] = true;
    $GLOBALS['cb_logged_in'] = true;

    TemplateRegistry::boot();
    cb_ext_assert( true === $GLOBALS['cb_provider_success'], 'valid external registration' );
    cb_ext_assert( 7 === count( TemplateRegistry::all() ), 'six WordPress and one extension' );
    cb_ext_assert(
        PlatformTransport::authorized_resource( 'base/mail', Repository::MAIL, 'bookings.customer-confirmed' ),
        'active registered native extension resource permitted for admin'
    );
    cb_ext_assert(
        PlatformTransport::authorized_resource( 'base/mail', Repository::MAIL, 'wordpress.password-reset' ),
        'WordPress resource still permitted'
    );
    foreach ( [
        [ 'base/mail', Repository::MAIL, 'bookings.unknown' ],
        [ 'base/mail', Repository::MAIL, 'bookings.customer-unknown' ],
        [ 'base/mail', Repository::REPORTS, 'bookings.customer-confirmed' ],
        [ 'base/mail', Repository::MAIL, 'bookings.customer-confirmed/other' ],
        [ 'base/reports', Repository::MAIL, 'bookings.customer-confirmed' ],
        [ 'base/mail', Repository::MAIL, 'wordpress.invented' ],
    ] as [ $profile, $type, $id ] ) {
        cb_ext_assert( ! PlatformTransport::authorized_resource( $profile, $type, $id ),
            'unknown or cross-profile identity denied: ' . $id );
    }

    cb_ext_assert( PlatformTransport::permission( new WP_REST_Request( 'good' ) ),
        'authenticated WordPress REST nonce' );
    cb_ext_assert( ! PlatformTransport::permission( new WP_REST_Request( 'bad' ) ),
        'invalid REST nonce denied' );
    $GLOBALS['cb_logged_in'] = false;
    cb_ext_assert( ! PlatformTransport::permission( new WP_REST_Request( 'good' ) ),
        'anonymous actor denied despite nonce' );
    $GLOBALS['cb_logged_in'] = true;
    $GLOBALS['cb_can_manage'] = false;
    cb_ext_assert( ! PlatformTransport::authorized_resource(
        'base/mail', Repository::MAIL, 'bookings.customer-confirmed' ),
        'non-admin cannot open extension' );
    cb_ext_assert( ! PlatformTransport::authorized_resource(
        'base/mail', Repository::MAIL, 'wordpress.password-reset' ),
        'non-admin cannot open WordPress resource' );
    $GLOBALS['cb_can_manage'] = true;
    DesignerState::$enabled = false;
    cb_ext_assert( ! PlatformTransport::authorized_resource(
        'base/mail', Repository::MAIL, 'bookings.customer-confirmed' ),
        'disabled Designer denies resource' );
    DesignerState::$enabled = true;

    $options = ( new \ReflectionMethod( Page::class, 'template_options' ) )->invoke(
        null, TemplateRegistry::all(), 'bookings.customer-confirmed'
    );
    cb_ext_assert( 2 === substr_count( $options, '<optgroup ' ), 'two provider groups' );
    cb_ext_assert( str_contains( $options, '<optgroup label="WordPress">' ), 'built-in group' );
    cb_ext_assert( str_contains( $options, '<optgroup label="Bookings">' ), 'extension group' );
    cb_ext_assert( 7 === substr_count( $options, '<option ' ), 'all templates rendered once' );
    cb_ext_assert( str_contains( $options,
        'value="bookings.customer-confirmed" selected="selected">Booking &amp; confirmation</option>' ),
        'selected extension template and safely escaped label' );
    cb_ext_assert( strpos( $options, 'label="WordPress"' ) < strpos( $options, 'label="Bookings"' ),
        'WordPress provider remains first' );

    // Provider deactivation: no current registration means neither picker
    // nor REST authorizes the stale resource. No document delete is invoked.
    $GLOBALS['cb_provider_active'] = false;
    TemplateRegistry::_reset_for_testing();
    cb_ext_assert( ! PlatformTransport::authorized_resource(
        'base/mail', Repository::MAIL, 'bookings.customer-confirmed' ),
        'inactive provider denies stale extension identity' );
    cb_ext_assert( PlatformTransport::authorized_resource(
        'base/mail', Repository::MAIL, 'wordpress.password-reset' ),
        'inactive extension does not block WordPress' );
    $inactive = ( new \ReflectionMethod( Page::class, 'template_options' ) )->invoke(
        null, TemplateRegistry::all(), 'wordpress.password-reset'
    );
    cb_ext_assert( 1 === substr_count( $inactive, '<optgroup ' ), 'inactive provider hidden in picker' );
    cb_ext_assert( ! str_contains( $inactive, 'bookings.customer-confirmed' ),
        'inactive provider not selectable' );
    $GLOBALS['cb_provider_active'] = true;
    TemplateRegistry::_reset_for_testing();
    cb_ext_assert( PlatformTransport::authorized_resource(
        'base/mail', Repository::MAIL, 'bookings.customer-confirmed' ),
        'reactivated provider identity restored without new storage authority' );

    // Caller cannot squat Base provider identities, even with a valid v1 doc.
    $imposter = $GLOBALS['cb_provider_template'];
    $imposter['id'] = 'core.unapproved';
    $imposter['provider'] = 'core';
    cb_ext_assert( ! TemplateRegistry::register( $imposter ), 'Base provider namespace reserved' );

    echo "Base MAIL-EXT-01B native provider editor admission and picker regression: PASS\n";
}
