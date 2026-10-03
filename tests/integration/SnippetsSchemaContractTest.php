<?php
declare(strict_types=1);

use CoreBlueprint\Core\Snippets\Schema;

final class CB_Base_Snippets_Schema_Contract_Test extends WP_UnitTestCase {

	public function test_runtime_location_validation_does_not_translate_labels(): void {
		$translation_calls = 0;
		$filter = static function ( string $translation, string $text, string $domain ) use ( &$translation_calls ): string {
			if ( 'core-blueprint' === $domain ) {
				++$translation_calls;
			}
			return $translation;
		};

		add_filter( 'gettext', $filter, 10, 3 );
		try {
			self::assertTrue( Schema::valid_location( 'php', 'plugins_loaded' ) );
			self::assertTrue( Schema::valid_location( 'css', 'frontend' ) );
			self::assertTrue( Schema::valid_location( 'js', 'wp_footer' ) );
			self::assertTrue( Schema::valid_location( 'html', 'shortcode' ) );
			self::assertFalse( Schema::valid_location( 'php', 'frontend' ) );
			self::assertFalse( Schema::valid_location( 'css', 'wp_footer' ) );
		} finally {
			remove_filter( 'gettext', $filter, 10 );
		}

		self::assertSame( 0, $translation_calls );
	}

	public function test_ui_locations_defaults_and_runtime_validation_share_the_same_contract(): void {
		foreach ( Schema::TYPES as $type ) {
			$locations = Schema::locations_for_type( $type );
			self::assertNotEmpty( $locations, $type );
			self::assertTrue( Schema::valid_location( $type, Schema::default_location( $type ) ), $type );

			foreach ( array_keys( $locations ) as $location ) {
				self::assertTrue( Schema::valid_location( $type, (string) $location ), $type . ':' . $location );
			}
		}
	}
}
