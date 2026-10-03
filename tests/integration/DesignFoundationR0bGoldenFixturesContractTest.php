<?php
declare(strict_types=1);

final class CB_Design_Foundation_R0b_Golden_Fixtures_Contract_Test extends WP_UnitTestCase {

    /** @return array<string,mixed> */
    private static function baseline(): array {
        static $baseline = null;

        if ( is_array( $baseline ) ) {
            return $baseline;
        }

        $path = dirname( __DIR__ ) . '/fixtures/design-foundation/r0b/baseline.json';
        self::assertFileExists( $path );

        $contents = file_get_contents( $path );
        self::assertIsString( $contents );
        self::assertNotSame( '', trim( $contents ) );

        $decoded = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
        self::assertIsArray( $decoded );

        $baseline = $decoded;
        return $baseline;
    }

    public function test_fixture_set_pins_verified_source_baselines(): void {
        $baseline = self::baseline();
        $sources  = $baseline['source_baselines'];

        self::assertSame( 'core-blueprint-design-foundation-r0b', $baseline['fixture_set'] );
        self::assertSame( 1, $baseline['fixture_set_version'] );

        self::assertSame(
            'a33e04cff746b655eb6d4833fece3c8c3d8b51f8',
            $sources['base']['commit']
        );
        self::assertSame(
            '79bf9c6ea909dbe1e6b8257f5b1d05c30a69c2e1',
            $sources['certificates']['commit']
        );
        self::assertSame(
            '9eec7a9b2aef9a44f469649ce777a125749ab111',
            $sources['commerce_essentials']['commit']
        );

        self::assertSame(
            '37db9488802f0db87455fcab1fb24b7a17add286',
            $sources['base']['files']['src/PDF/Renderer.php']
        );
        self::assertSame(
            '31ce5fd07fd2b15865df0aa8d7f498ae80fd2d82',
            $sources['certificates']['files']['src/Rendering/CertificateHtml.php']
        );
        self::assertSame(
            '57aadabd613e1af629315498a173d12198cc5606',
            $sources['commerce_essentials']['files']['src/Documents/DocumentHtml.php']
        );
    }

    public function test_renderer_baseline_preserves_existing_hardened_boundary(): void {
        $baseline = self::baseline();
        $renderer = $baseline['renderer'];

        self::assertSame( 'CoreBlueprint\\Core\\PDF\\Api\\PdfApi', $renderer['public_extension_boundary'] );
        self::assertSame( 'CoreBlueprint\\Core\\PDF\\Renderer', $renderer['base_internal_renderer'] );
        self::assertSame( 'dompdf/dompdf', $renderer['backend']['name'] );
        self::assertSame( '3.1.6', $renderer['backend']['version'] );
        self::assertSame(
            '6d4b4eb8500f7a786da8868ba463a71b725a4005',
            $renderer['backend']['upstream_commit']
        );

        self::assertFalse( $renderer['security_policy']['remote_resources'] );
        self::assertFalse( $renderer['security_policy']['embedded_php'] );
        self::assertFalse( $renderer['security_policy']['embedded_javascript'] );
        self::assertTrue( $renderer['security_policy']['reject_foreign_loaded_dompdf'] );

        self::assertFalse( $renderer['compatibility_policy']['modern_browser_css_parity_guaranteed'] );
        self::assertFalse( $renderer['compatibility_policy']['pdfa_implied'] );
    }

    public function test_r0b_uses_semantic_golden_observables_instead_of_pdf_byte_hashes(): void {
        $baseline = self::baseline();

        self::assertFalse( $baseline['golden_strategy']['pdf_byte_hash'] );
        self::assertSame(
            'semantic-observables-and-pinned-render-provenance',
            $baseline['golden_strategy']['comparison']
        );
        self::assertTrue( $baseline['golden_strategy']['production_renderers_unchanged_by_r0b'] );
    }

    public function test_existing_production_fixtures_keep_external_data_consumer_owned(): void {
        $baseline = self::baseline();
        $fixtures = [];

        foreach ( $baseline['fixtures'] as $fixture ) {
            $fixtures[ $fixture['id'] ] = $fixture;
        }

        foreach (
            [
                'maintenance-report-current',
                'certificates-fixed-current',
                'commerce-financial-current',
            ] as $fixture_id
        ) {
            self::assertArrayHasKey( $fixture_id, $fixtures );
            self::assertSame( 'existing-production-baseline', $fixtures[ $fixture_id ]['status'] );
            self::assertTrue( $fixtures[ $fixture_id ]['consumer_owns_external_data'] );
            self::assertNotEmpty( $fixtures[ $fixture_id ]['migration_parity']['must_not_move_to_foundation'] );
        }

        self::assertSame(
            1,
            $fixtures['maintenance-report-current']['observables']['snapshot_version']
        );
        self::assertSame(
            '1.0',
            $fixtures['certificates-fixed-current']['legacy_design_schema_version']
        );
        self::assertSame(
            [ 'invoice', 'credit_note' ],
            $fixtures['commerce-financial-current']['observables']['document_types']
        );
    }

    public function test_locale_mismatches_are_recorded_as_migration_differences_not_foundation_contracts(): void {
        $baseline = self::baseline();
        $fixtures = [];

        foreach ( $baseline['fixtures'] as $fixture ) {
            $fixtures[ $fixture['id'] ] = $fixture;
        }

        self::assertNotEmpty( $fixtures['certificates-fixed-current']['known_migration_differences'] );
        self::assertNotEmpty( $fixtures['commerce-financial-current']['known_migration_differences'] );

        $certificate_delta = implode(
            ' ',
            $fixtures['certificates-fixed-current']['known_migration_differences']
        );
        $commerce_delta = implode(
            ' ',
            $fixtures['commerce-financial-current']['known_migration_differences']
        );

        self::assertStringContainsString( 'explicit consumer-owned render locale', $certificate_delta );
        self::assertStringContainsString( 'explicit consumer-owned render locale', $commerce_delta );
    }

    public function test_shipping_label_stays_a_non_production_r3b_proof_definition(): void {
        $baseline = self::baseline();
        $fixtures = [];

        foreach ( $baseline['fixtures'] as $fixture ) {
            $fixtures[ $fixture['id'] ] = $fixture;
        }

        $shipping = $fixtures['shipping-label-r3b-proof-definition'];

        self::assertSame( 'synthetic-proof-definition-only', $shipping['status'] );
        self::assertSame( 'fixture.shipping', $shipping['consumer_provider'] );
        self::assertTrue( $shipping['provider_id_is_test_only'] );
        self::assertContains( 'public schema/API shape', $shipping['explicitly_deferred_to_r3b'] );
        self::assertContains( 'actual PDF output', $shipping['required_proof'] );
    }

    public function test_fixture_definitions_do_not_depend_on_remote_or_arbitrary_file_urls(): void {
        $baseline = self::baseline();
        $fixtures = json_encode( $baseline['fixtures'], JSON_THROW_ON_ERROR );

        self::assertStringNotContainsString( 'file://', $fixtures );
        self::assertStringNotContainsString( 'http://', $fixtures );
        self::assertStringNotContainsString( 'https://', $fixtures );
    }
}
