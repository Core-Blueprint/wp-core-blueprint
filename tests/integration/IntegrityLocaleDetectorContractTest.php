<?php
declare(strict_types=1);

use CB\Core\Integrity\Scanner\LocaleDetector;

final class CB_Base_Integrity_Locale_Detector_Contract_Test extends WP_UnitTestCase {

	private mixed $original_network_locale = null;
	private bool $network_locale_existed = false;

	public function set_up(): void {
		parent::set_up();

		$this->network_locale_existed = false !== get_site_option( 'WPLANG', false );
		$this->original_network_locale = get_site_option( 'WPLANG', null );
	}

	public function tear_down(): void {
		if ( $this->network_locale_existed ) {
			update_site_option( 'WPLANG', $this->original_network_locale );
		} else {
			delete_site_option( 'WPLANG' );
		}

		parent::tear_down();
	}

	public function test_candidate_locales_use_current_wordpress_sources_without_direct_legacy_constant_branch(): void {
		update_site_option( 'WPLANG', 'de_DE' );

		$detector = new LocaleDetector();
		$method = new ReflectionMethod( LocaleDetector::class, 'candidate_locales' );
		$method->setAccessible( true );

		$candidates = $method->invoke( $detector );

		self::assertIsArray( $candidates );
		self::assertContains( get_locale(), $candidates );
		self::assertContains( 'en_US', $candidates );
		self::assertContains( 'de_DE', $candidates );
		self::assertSame( $candidates, array_values( array_unique( $candidates ) ) );

		$source = file_get_contents( CB_CORE_DIR . 'src/Integrity/Scanner/LocaleDetector.php' );
		self::assertIsString( $source );
		self::assertStringNotContainsString( "constant( 'WPLANG' )", $source );
		self::assertStringContainsString( "get_site_option( 'WPLANG', '' )", $source );
		self::assertStringContainsString( 'get_available_languages()', $source );
	}
}
