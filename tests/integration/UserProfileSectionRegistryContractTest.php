<?php
declare(strict_types=1);

use CoreBlueprint\Core\Admin\UserProfileSectionRegistry;

final class CB_Base_User_Profile_Section_Registry_Contract_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		UserProfileSectionRegistry::_reset_for_testing();
		wp_dequeue_style( 'cb-core-css-form-composition-native' );
		wp_dequeue_style( 'cb-core-css-tokens' );
	}

	public function tear_down(): void {
		UserProfileSectionRegistry::_reset_for_testing();
		wp_dequeue_style( 'cb-core-css-form-composition-native' );
		wp_dequeue_style( 'cb-core-css-tokens' );
		parent::tear_down();
	}

	public function test_ups1_registration_is_lifecycle_bound_and_deterministically_ordered(): void {
		$register = static function (): void {
			UserProfileSectionRegistry::register(
				'test-profile-later',
				[
					'title'    => 'Later',
					'order'    => 20,
					'contexts' => [ UserProfileSectionRegistry::CONTEXT_SELF ],
					'renderer' => static function (): void { echo '<p>later</p>'; },
				]
			);
			UserProfileSectionRegistry::register(
				'test-profile-earlier',
				[
					'title'    => 'Earlier',
					'order'    => 10,
					'contexts' => [ UserProfileSectionRegistry::CONTEXT_SELF ],
					'renderer' => static function (): void { echo '<p>earlier</p>'; },
				]
			);
		};
		add_action( 'cb_core_register_user_profile_sections', $register, 99 );
		try {
			$ids = array_keys( UserProfileSectionRegistry::all() );
			self::assertLessThan(
				array_search( 'test-profile-later', $ids, true ),
				array_search( 'test-profile-earlier', $ids, true )
			);
		} finally {
			remove_action( 'cb_core_register_user_profile_sections', $register, 99 );
		}
	}

	public function test_ups2_context_and_visibility_are_enforced_before_rendering(): void {
		$register = static function (): void {
			UserProfileSectionRegistry::register(
				'test-profile-visible',
				[
					'title'    => 'Visible self section',
					'order'    => 10,
					'contexts' => [ UserProfileSectionRegistry::CONTEXT_SELF ],
					'visible'  => static fn( WP_User $user, string $context ): bool => $user->ID > 0 && UserProfileSectionRegistry::CONTEXT_SELF === $context,
					'renderer' => static function (): void { echo '<p>visible-renderer</p>'; },
				]
			);
			UserProfileSectionRegistry::register(
				'test-profile-edit-only',
				[
					'title'    => 'Edit only',
					'order'    => 20,
					'contexts' => [ UserProfileSectionRegistry::CONTEXT_EDIT ],
					'renderer' => static function (): void { echo '<p>edit-renderer</p>'; },
				]
			);
		};
		add_action( 'cb_core_register_user_profile_sections', $register, 99 );

		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		wp_set_current_user( $user_id );

		try {
			ob_start();
			UserProfileSectionRegistry::render_self( $user );
			$html = (string) ob_get_clean();

			self::assertStringContainsString( 'Visible self section', $html );
			self::assertStringContainsString( 'visible-renderer', $html );
			self::assertStringNotContainsString( 'Edit only', $html );
			self::assertStringNotContainsString( 'edit-renderer', $html );
		} finally {
			remove_action( 'cb_core_register_user_profile_sections', $register, 99 );
		}
	}

	public function test_ups3_profile_screen_loads_wp_native_form_composition_without_adding_core_tokens(): void {
		$tokens_before = wp_style_is( 'cb-core-css-tokens', 'enqueued' );

		UserProfileSectionRegistry::enqueue_assets( 'profile.php' );

		self::assertTrue( wp_style_is( 'cb-core-css-form-composition-native', 'enqueued' ) );
		self::assertSame( $tokens_before, wp_style_is( 'cb-core-css-tokens', 'enqueued' ) );
	}
}
