<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Mail\Designer\NativeTemplateSource;
use CoreBlueprint\Core\Mail\Designer\TemplateRegistry;
use CoreBlueprint\Core\Mail\Designer\WordPressTemplates;
use CoreBlueprint\Core\Mail\DesignerState;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

final class CB_Template_Library_Native_Mail_Source_Test extends WP_UnitTestCase {
    private int $operator = 0;
    private bool $was_enabled = false;

    public function set_up(): void {
        parent::set_up();
        $this->operator = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $user = get_userdata( $this->operator );
        self::assertInstanceOf( WP_User::class, $user );
        self::assertTrue(
            PrivilegedAccessRegistry::approve( $user, 0, 'template_library_native_mail_fixture' )
        );
        wp_set_current_user( $this->operator );
        self::assertTrue( current_user_can( 'manage_options' ) );
        $this->was_enabled = DesignerState::is_enabled();
        if ( ! $this->was_enabled ) {
            DesignerState::set_enabled( true, 'template_library_native_mail_fixture' );
        }
        TemplateSourceRegistry::_reset_for_testing();
        TemplateSourceRegistry::collect();
    }

    public function tear_down(): void {
        TemplateSourceRegistry::_reset_for_testing();
        TemplateRegistry::_reset_for_testing();
        if ( DesignerState::is_enabled() !== $this->was_enabled ) {
            DesignerState::set_enabled( $this->was_enabled, 'template_library_native_mail_fixture' );
        }
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_real_native_mail_templates_are_discoverable_without_document_payload(): void {
        $sources = TemplateSourceRegistry::sources();
        self::assertArrayHasKey( NativeTemplateSource::SOURCE_ID, $sources );
        self::assertSame( [
            'extension' => 'core-blueprint', 'designer' => 'mail',
        ], $sources[ NativeTemplateSource::SOURCE_ID ] );

        $page = TemplateSourceRegistry::browse_designer(
            'core-blueprint', 'mail',
            new TemplateQuery( source: NativeTemplateSource::SOURCE_ID, limit: 2 )
        );
        self::assertCount( 2, $page->items );
        self::assertNotNull( $page->next_cursor );
        foreach ( $page->items as $item ) {
            self::assertSame( NativeTemplateSource::SOURCE_ID, $item->source );
            self::assertSame( 'built-in', $item->origin );
            self::assertSame( 'mail', $item->designer );
            self::assertSame( 'en', $item->locale );
            self::assertArrayNotHasKey( 'document', get_object_vars( $item ) );
            self::assertTrue( ( new NativeTemplateSource() )->can_view( $item->id, $this->operator ) );
        }
        self::assertSame( [], TemplateSourceRegistry::browse_designer(
            'core-blueprint', 'reports',
            new TemplateQuery( source: NativeTemplateSource::SOURCE_ID )
        )->items );
    }

    public function test_builtin_and_external_registered_defaults_share_the_same_read_only_source(): void {
        $external = [
            'id' => 'bookings.fixture',
            'provider' => 'bookings',
            'group' => 'bookings',
            'label' => 'Booking confirmation',
            'description' => 'Registered provider preview',
            'document' => WordPressTemplates::default_document( 'wordpress.new-user' ),
        ];
        self::assertTrue( TemplateRegistry::register( $external ) );

        $page = TemplateSourceRegistry::browse_global(
            new TemplateQuery( source: NativeTemplateSource::SOURCE_ID, category: 'bookings' )
        );
        self::assertCount( 1, $page->items );
        self::assertSame( 'bookings.fixture', $page->items[0]->id );
        self::assertSame( 'Booking confirmation', $page->items[0]->title );

        $source = new NativeTemplateSource();
        $categories = $source->categories( $this->operator );
        self::assertArrayHasKey( 'bookings', $categories );
        self::assertArrayHasKey( 'wordpress', $categories );

        $navigation = $source->navigation( $page->items[0], $this->operator );
        self::assertNotNull( $navigation->preview );
        self::assertNull( $navigation->manage );
        self::assertSame( 'Mail Designer', $navigation->preview['label'] );
        self::assertStringContainsString( 'page=core-blueprint-mail', $navigation->preview['url'] );
        self::assertStringContainsString( 'bookings.fixture', $navigation->preview['url'] );
        self::assertFalse( $source->can_manage( 'bookings.fixture', $this->operator ) );
    }

    public function test_source_cursor_is_bounded_and_metadata_only(): void {
        $query = new TemplateQuery( source: NativeTemplateSource::SOURCE_ID, limit: 1 );
        $cursor = null;
        $found = [];
        for ( $i = 0; $i < 12; ++$i ) {
            $page = TemplateSourceRegistry::browse_global( $query, $cursor );
            foreach ( $page->items as $item ) {
                self::assertArrayNotHasKey( 'document', get_object_vars( $item ) );
                $found[] = $item->id;
            }
            $cursor = $page->next_cursor;
            if ( null === $cursor ) break;
        }
        self::assertCount( count( TemplateRegistry::all() ), $found );
        self::assertCount( count( $found ), array_unique( $found ) );
    }

    public function test_unauthorized_users_cannot_browse_or_launch_a_mail_document(): void {
        $source = new NativeTemplateSource();
        $id = 'wordpress.new-user';
        self::assertTrue( $source->can_access_designer_resource(
            Repository::MAIL, $id, $this->operator
        ) );
        self::assertFalse( $source->can_access_designer_resource(
            'base.reports', $id, $this->operator
        ) );
        self::assertFalse( $source->can_access_designer_resource(
            Repository::MAIL, 'unknown.template', $this->operator
        ) );
        set_current_screen( 'front' );
        self::assertFalse( $source->can_launch_designer(
            Repository::MAIL, $id, $this->operator
        ) );
        set_current_screen( 'front' );

        $subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $subscriber );
        self::assertSame( [], TemplateSourceRegistry::sources() );
        self::assertSame( [], TemplateSourceRegistry::browse_global( new TemplateQuery() )->items );
        self::assertFalse( $source->can_view( $id, $subscriber ) );
        self::assertFalse( $source->can_access_designer_resource(
            Repository::MAIL, $id, $subscriber
        ) );
        self::assertSame( [], $source->categories( $subscriber ) );
    }
}
