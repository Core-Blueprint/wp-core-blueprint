<?php
declare(strict_types=1);

use CB\Core\Security\TwoFactor\ProviderDetector;

final class CB_Two_Factor_Provider_Test_State {
	/** @var array<string,array<int,bool>> */
	public static array $active = [];

	public static function is_active( string $provider, int $user_id ): bool {
		return ! empty( self::$active[ $provider ][ $user_id ] );
	}

	public static function reset(): void {
		self::$active = [];
	}
}

if ( ! class_exists( '\\WordfenceLS\\Controller_Users' ) ) {
	eval( 'namespace WordfenceLS; final class Controller_Users { public static function shared(): self { return new self(); } public function has_2fa_active( $user ): bool { return \\CB_Two_Factor_Provider_Test_State::is_active( "wordfence", (int) $user->ID ); } }' );
}
if ( ! class_exists( '\\Two_Factor_Core' ) ) {
	eval( 'final class Two_Factor_Core { public static function is_user_using_two_factor( $user = null ): bool { $id = $user instanceof \\WP_User ? (int) $user->ID : (int) $user; return \\CB_Two_Factor_Provider_Test_State::is_active( "two-factor", $id ); } }' );
}
if ( ! class_exists( '\\WP2FA\\Admin\\Helpers\\User_Helper' ) ) {
	eval( 'namespace WP2FA\\Admin\\Helpers; final class User_Helper { public static function is_user_using_two_factor( $user = null ): bool { $id = $user instanceof \\WP_User ? (int) $user->ID : (int) $user; return \\CB_Two_Factor_Provider_Test_State::is_active( "wp-2fa", $id ); } }' );
}

final class CB_Base_Two_Factor_Provider_Detector_Contract_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		CB_Two_Factor_Provider_Test_State::reset();
	}

	public function tear_down(): void {
		CB_Two_Factor_Provider_Test_State::reset();
		parent::tear_down();
	}

	public function test_tp1_loaded_provider_classes_do_not_count_without_user_level_2fa(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		self::assertSame( [], ProviderDetector::providers_for_user( $user ) );
		self::assertFalse( ProviderDetector::external_provider_owns_user( $user ) );
		self::assertNull( ProviderDetector::primary_provider( $user ) );
	}

	public function test_tp2_each_supported_provider_is_detected_for_the_exact_user_only(): void {
		$protected_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other_id     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$protected    = get_userdata( $protected_id );
		$other        = get_userdata( $other_id );

		self::assertInstanceOf( WP_User::class, $protected );
		self::assertInstanceOf( WP_User::class, $other );

		foreach ( [
			ProviderDetector::WORDFENCE,
			ProviderDetector::TWO_FACTOR,
			ProviderDetector::WP_2FA,
		] as $provider ) {
			CB_Two_Factor_Provider_Test_State::reset();
			CB_Two_Factor_Provider_Test_State::$active[ $provider ][ $protected_id ] = true;

			self::assertSame( [ $provider ], ProviderDetector::providers_for_user( $protected ) );
			self::assertTrue( ProviderDetector::external_provider_owns_user( $protected ) );
			self::assertSame( [], ProviderDetector::providers_for_user( $other ) );
		}
	}

	public function test_tp3_multiple_external_providers_are_reported_deterministically_and_base_can_stand_down_once(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		foreach ( [
			ProviderDetector::WORDFENCE,
			ProviderDetector::TWO_FACTOR,
			ProviderDetector::WP_2FA,
		] as $provider ) {
			CB_Two_Factor_Provider_Test_State::$active[ $provider ][ $user_id ] = true;
		}

		self::assertSame(
			[
				ProviderDetector::WORDFENCE,
				ProviderDetector::TWO_FACTOR,
				ProviderDetector::WP_2FA,
			],
			ProviderDetector::providers_for_user( $user )
		);
		self::assertSame( ProviderDetector::WORDFENCE, ProviderDetector::primary_provider( $user ) );
		self::assertTrue( ProviderDetector::external_provider_owns_user( $user ) );
	}
}
