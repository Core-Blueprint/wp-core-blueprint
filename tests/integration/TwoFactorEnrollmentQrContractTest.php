<?php
declare(strict_types=1);

final class CB_Base_Two_Factor_Enrollment_QR_Contract_Test extends WP_UnitTestCase {

	public function test_tq1_qr_encoder_is_local_pinned_and_licensed(): void {
		$vendor = file_get_contents( CB_CORE_DIR . 'assets/js/vendor/qrcode-generator-2.0.4.js' );
		$notice = file_get_contents( CB_CORE_DIR . 'licenses/QRCODE-GENERATOR.md' );

		self::assertIsString( $vendor );
		self::assertIsString( $notice );
		self::assertStringContainsString( 'export const qrcode', $vendor );
		self::assertStringContainsString( 'Version: 2.0.4', $notice );
		self::assertStringContainsString( 'tag `js2.0.4`', $notice );
		self::assertStringContainsString( 'MIT License', $notice );
		self::assertStringContainsString( 'Runtime network access: none.', $notice );
	}

	public function test_tq2_enrollment_runtime_generates_qr_locally_without_secret_transport_or_storage(): void {
		$runtime = file_get_contents( CB_CORE_DIR . 'assets/js/features/two-factor-enrollment.js' );

		self::assertIsString( $runtime );
		self::assertStringContainsString( "../vendor/qrcode-generator-2.0.4.js", $runtime );
		self::assertStringContainsString( "qrcode( 0, 'M' )", $runtime );
		self::assertStringContainsString( 'target.replaceChildren( svg );', $runtime );
		self::assertStringContainsString( 'clipboard.enhance( copyButton', $runtime );

		foreach ( [ 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'localStorage', 'sessionStorage', 'document.cookie', 'console.' ] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $runtime );
		}
	}

	public function test_tq3_provisioning_layer_has_no_persistence_or_logging_responsibility(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/Security/TwoFactor/Provisioning.php' );

		self::assertIsString( $source );
		self::assertStringContainsString( "'algorithm' => 'SHA1'", $source );
		self::assertStringContainsString( "'digits'    => Totp::DIGITS", $source );
		self::assertStringContainsString( "'period'    => Totp::PERIOD", $source );

		foreach ( [ 'update_option', 'add_option', 'set_transient', 'update_user_meta', 'error_log', 'wp_remote_' ] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $source );
		}
	}

	public function test_tq4_setup_surface_consumes_provisioning_clipboard_and_aligned_action_contracts(): void {
		$controller = file_get_contents( CB_CORE_DIR . 'src/Security/TwoFactor/ProfileController.php' );
		$screen = file_get_contents( CB_CORE_DIR . 'src/Admin/SecureActionScreen.php' );
		$css = file_get_contents( CB_CORE_DIR . 'assets/css/pages/secure-action.css' );

		self::assertIsString( $controller );
		self::assertIsString( $screen );
		self::assertIsString( $css );

		self::assertStringContainsString( 'Provisioning::uri( $user, $secret )', $controller );
		self::assertStringContainsString( 'data-cb-two-factor-provisioning-uri=', $controller );
		self::assertStringContainsString( 'data-cb-two-factor-copy-secret=', $controller );
		self::assertStringContainsString( '@cb-core/two-factor-enrollment', $controller );
		self::assertStringContainsString( 'self::enrollment_asset_version()', $controller );
		self::assertStringContainsString( "hash_file( 'sha256', $path )", $controller );
		self::assertStringContainsString( "'foundation.clipboard'", $screen );
		self::assertStringContainsString( 'background: #fff;', $css );
		self::assertStringContainsString( 'margin-left: var(--cb-secure-action-step-offset);', $css );
	}
}
