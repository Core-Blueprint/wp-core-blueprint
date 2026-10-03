<?php
declare(strict_types=1);

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Security\Failsafe;

final class CB_Base_Failsafe_Hardening_Contract_Test extends WP_UnitTestCase {

	private const REJECT_AUDIT_GATE = 'cb_core_failsafe_rejected_audit_gate';

	public function set_up(): void {
		parent::set_up();
		$_GET = [];
		delete_transient( self::REJECT_AUDIT_GATE );
	}

	public function tear_down(): void {
		$_GET = [];
		delete_transient( self::REJECT_AUDIT_GATE );
		parent::tear_down();
	}

	public function test_rejected_bypass_audit_writes_are_bounded_per_abuse_window(): void {
		$event_type = AuditLog::normalize_event_type( 'failsafe.bypass_url_rejected' );
		$before = AuditLog::query( [
			'event_type' => $event_type,
			'per_page'   => 1,
		] )['total'];

		$_GET[ Failsafe::BYPASS_PARAM ] = 'malformed';
		Failsafe::maybe_handle_bypass_url();
		Failsafe::maybe_handle_bypass_url();
		Failsafe::maybe_handle_bypass_url();

		$during_gate = AuditLog::query( [
			'event_type' => $event_type,
			'per_page'   => 1,
		] )['total'];
		self::assertSame( $before + 1, $during_gate, 'Repeated rejected bypass hits produced repeated database audit writes.' );

		delete_transient( self::REJECT_AUDIT_GATE );
		Failsafe::maybe_handle_bypass_url();

		$after_new_window = AuditLog::query( [
			'event_type' => $event_type,
			'per_page'   => 1,
		] )['total'];
		self::assertSame( $before + 2, $after_new_window, 'A new abuse window did not restore rejected-attempt audit visibility.' );
	}

	public function test_rotated_bypass_token_is_only_persisted_as_a_password_hash(): void {
		$token  = Failsafe::rotate_token();
		$stored = (string) get_option( CB_CORE_BYPASS_TOK, '' );

		self::assertSame( 64, strlen( $token ) );
		self::assertNotSame( $token, $stored, 'The plaintext bypass token was persisted as the stored option value.' );
		self::assertTrue( wp_check_password( $token, $stored ), 'The persisted bypass token hash does not verify the returned one-time token.' );
	}

	public function test_admin_rotation_has_no_server_side_plaintext_flash_path(): void {
		$handler  = (string) file_get_contents( CB_CORE_DIR . 'src/Ajax/Handlers/Failsafe.php' );
		$page     = (string) file_get_contents( CB_CORE_DIR . 'src/Admin/Pages/Safeguards.php' );
		$template = (string) file_get_contents( CB_CORE_DIR . 'templates/failsafe.php' );
		$client   = (string) file_get_contents( CB_CORE_DIR . 'assets/js/features/failsafe.js' );

		self::assertStringNotContainsString( 'cb_core_new_token_', $handler );
		self::assertStringNotContainsString( 'cb_core_new_token_', $page );
		self::assertStringNotContainsString( '$new_token', $template );
		self::assertStringNotContainsString( 'window.location.href', $client );
		self::assertStringContainsString( "'bypass_url' => \$bypass_url", $handler );
		self::assertStringContainsString( 'revealBypassUrl( response.data )', $client );
	}
}
