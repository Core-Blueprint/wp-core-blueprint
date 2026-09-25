<?php
declare(strict_types=1);

use CB\Core\Detector;
use CB\Core\Security\TwoFactor\ProviderDetector;

final class CB_Two_Factor_Provider_Test_State {
	/** @var array<string,array<int,bool>> */
	public static array $active = [];

	/** @var array<int,bool> */
	public static array $wordfence_required = [];

	public static function is_active( string $provider, int $user_id ): bool {
		return ! empty( self::$active[ $provider ][ $user_id ] );
	}

	public static function reset(): void {
		self::$active = [];
		self::$wordfence_required = [];
	}
}

final class CB_Two_Factor_Wordfence_Controller_Fixture {
	public static function shared(): self {
		static $instance;
		if ( ! $instance instanceof self ) {
			$instance = new self();
		}
		return $instance;
	}

	public function has_2fa_active( WP_User $user ): bool {
		return CB_Two_Factor_Provider_Test_State::is_active(
			ProviderDetector::WORDFENCE,
			(int) $user->ID
		);
	}

	public function requires_2fa( WP_User $user ): bool {
		return ! empty( CB_Two_Factor_Provider_Test_State::$wordfence_required[ (int) $user->ID ] );
	}
}

final class CB_Two_Factor_Core_Fixture {
	public static function is_user_using_two_factor( WP_User|int|null $user = null ): bool {
		$user_id = $user instanceof WP_User ? (int) $user->ID : (int) $user;
		return CB_Two_Factor_Provider_Test_State::is_active(
			ProviderDetector::TWO_FACTOR,
			$user_id
		);
	}
}

if ( ! class_exists( '\\WordfenceLS\\Controller_Users' ) ) {
	class_alias(
		CB_Two_Factor_Wordfence_Controller_Fixture::class,
		'\\WordfenceLS\\Controller_Users'
	);
}
if ( ! class_exists( '\\Two_Factor_Core' ) ) {
	class_alias(
		CB_Two_Factor_Core_Fixture::class,
		'\\Two_Factor_Core'
	);
}

final class CB_Base_Two_Factor_Provider_Detector_Contract_Test extends WP_UnitTestCase {

	/** @var string[] */
	private array $original_active_plugins = [];

	public function set_up(): void {
		parent::set_up();
		$this->original_active_plugins = (array) get_option( 'active_plugins', [] );
		update_option( 'active_plugins', [], false );
		Detector::invalidate_cache();
		CB_Two_Factor_Provider_Test_State::reset();
	}

	public function tear_down(): void {
		update_option( 'active_plugins', $this->original_active_plugins, false );
		Detector::invalidate_cache();
		CB_Two_Factor_Provider_Test_State::reset();
		parent::tear_down();
	}

	public function test_tp1_loaded_provider_classes_do_not_count_without_user_level_2fa(): void {
		$user = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertInstanceOf( WP_User::class, $user );

		self::assertSame( [], ProviderDetector::providers_for_user( $user ) );
		self::assertFalse( ProviderDetector::external_provider_owns_user( $user ) );
		self::assertNull( ProviderDetector::primary_provider( $user ) );
	}

	public function test_tp2_wordfence_and_two_factor_are_detected_for_the_exact_user_only(): void {
		$protected_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other_id     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$protected    = get_userdata( $protected_id );
		$other        = get_userdata( $other_id );

		self::assertInstanceOf( WP_User::class, $protected );
		self::assertInstanceOf( WP_User::class, $other );

		foreach ( [
			ProviderDetector::WORDFENCE,
			ProviderDetector::TWO_FACTOR,
		] as $provider ) {
			CB_Two_Factor_Provider_Test_State::reset();
			CB_Two_Factor_Provider_Test_State::$active[ $provider ][ $protected_id ] = true;

			self::assertSame( [ $provider ], ProviderDetector::providers_for_user( $protected ) );
			self::assertTrue( ProviderDetector::external_provider_owns_user( $protected ) );
			self::assertSame( [], ProviderDetector::providers_for_user( $other ) );
		}
	}

	public function test_tp3_wordfence_required_policy_owns_user_before_enrollment(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		CB_Two_Factor_Provider_Test_State::$wordfence_required[ $user_id ] = true;

		self::assertSame( [ ProviderDetector::WORDFENCE ], ProviderDetector::providers_for_user( $user ) );
		self::assertTrue( ProviderDetector::external_provider_owns_user( $user ) );
	}

	public function test_tp4_wp_2fa_uses_conservative_active_plugin_delegation(): void {
		$first  = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$second = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertInstanceOf( WP_User::class, $first );
		self::assertInstanceOf( WP_User::class, $second );

		update_option( 'active_plugins', [ 'wp-2fa/wp-2fa.php' ], false );
		Detector::invalidate_cache();

		self::assertSame( [ ProviderDetector::WP_2FA ], ProviderDetector::providers_for_user( $first ) );
		self::assertSame( [ ProviderDetector::WP_2FA ], ProviderDetector::providers_for_user( $second ) );
	}

	public function test_tp5_multiple_external_owners_are_reported_deterministically(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		CB_Two_Factor_Provider_Test_State::$active[ ProviderDetector::WORDFENCE ][ $user_id ] = true;
		CB_Two_Factor_Provider_Test_State::$active[ ProviderDetector::TWO_FACTOR ][ $user_id ] = true;
		update_option( 'active_plugins', [ 'wp-2fa/wp-2fa.php' ], false );
		Detector::invalidate_cache();

		self::assertSame(
			[
				ProviderDetector::WORDFENCE,
				ProviderDetector::TWO_FACTOR,
				ProviderDetector::WP_2FA,
			],
			ProviderDetector::providers_for_user( $user )
		);
		self::assertSame( ProviderDetector::WORDFENCE, ProviderDetector::primary_provider( $user ) );
	}
}
