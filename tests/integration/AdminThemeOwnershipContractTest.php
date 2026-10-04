<?php
declare(strict_types=1);

final class CB_Base_Admin_Theme_Ownership_Contract_Test extends WP_UnitTestCase {

	public function test_admin_theme_is_single_owner_of_admin_presentation_hooks(): void {
		$core  = (string) file_get_contents( CB_CORE_DIR . 'src/Core.php' );
		$theme = (string) file_get_contents( CB_CORE_DIR . 'src/UI/AdminTheme.php' );

		self::assertStringNotContainsString(
			"[ Themes::class, 'emit_prepaint_hooks' ]",
			$core
		);

		self::assertStringNotContainsString(
			"[ Themes::class, 'filter_admin_body_class' ]",
			$core
		);

		self::assertStringNotContainsString(
			'take_theme_hook_ownership',
			$theme
		);

		self::assertStringNotContainsString(
			"remove_action( 'admin_head'",
			$theme
		);

		self::assertStringContainsString(
			"add_action( 'admin_head', [ self::class, 'emit_prepaint_hooks' ], 0 );",
			$theme
		);

		self::assertStringContainsString(
			"add_filter( 'admin_body_class', [ self::class, 'filter_admin_body_class' ], 5 );",
			$theme
		);
	}
}
