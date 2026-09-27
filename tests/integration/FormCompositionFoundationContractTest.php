<?php
declare(strict_types=1);

final class CB_Base_Form_Composition_Foundation_Contract_Test extends WP_UnitTestCase {

	public function test_fc1_form_actions_exists_in_core_and_wp_native_presentations(): void {
		$root = dirname( __DIR__, 2 );
		$core_css = file_get_contents( $root . '/assets/css/layout.css' );
		$native_css = file_get_contents( $root . '/assets/css/components/form-composition-native.css' );

		self::assertIsString( $core_css );
		self::assertIsString( $native_css );

		foreach ( [ $core_css, $native_css ] as $css ) {
			self::assertStringContainsString( '.cb-core-form-actions', $css );
			self::assertStringContainsString( 'display: flex', $css );
			self::assertStringContainsString( 'flex-wrap: wrap', $css );
			self::assertStringContainsString( 'align-items: center', $css );
		}
	}

	public function test_fc2_form_actions_contract_keeps_vertical_spacing_and_button_presentation_outside_the_primitive(): void {
		$root = dirname( __DIR__, 2 );
		$doc = file_get_contents( $root . '/docs/FORM-COMPOSITION-FOUNDATION.md' );
		$foundation = file_get_contents( $root . '/docs/foundation-v1-contract.md' );

		self::assertIsString( $doc );
		self::assertIsString( $foundation );
		self::assertStringContainsString( '## Form Actions contract', $doc );
		self::assertStringContainsString( 'does **not** own vertical', $doc );
		self::assertStringContainsString( 'button width', $doc );
		self::assertStringContainsString( 'Form Actions', $foundation );
		self::assertStringContainsString( '.cb-core-form-actions', $foundation );
	}
}
