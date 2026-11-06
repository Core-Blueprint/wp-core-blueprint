<?php
declare(strict_types=1);

use CoreBlueprint\Core\Integrity\Rest\ScanController;
use CoreBlueprint\Core\Support\ThrowableBoundary;

final class CB_Base_Unexpected_Exception_Output_Contract_Test extends WP_UnitTestCase {

	public function test_f01_quarantine_unexpected_errors_never_project_throwable_message(): void {
		$secret = 'secret=do-not-disclose';

		$method = new ReflectionMethod( ScanController::class, 'unexpected_quarantine_error' );
		$error = $method->invoke(
			new ScanController(),
			new RuntimeException( '/private/path/' . $secret ),
			'cb_integrity_quarantine_failed',
			'quarantine_action_failed',
			409
		);

		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'cb_integrity_quarantine_failed', $error->get_error_code() );
		self::assertSame( 409, $error->get_error_data()['status'] ?? null );
		self::assertStringNotContainsString( $secret, $error->get_error_message() );
		self::assertStringNotContainsString( '/private/path/', $error->get_error_message() );
	}

	public function test_f01_console_does_not_project_raw_execution_exceptions(): void {
		$source = (string) file_get_contents( CB_CORE_DIR . 'src/Console/Rest/RunController.php' );
		self::assertStringContainsString( "ThrowableBoundary::context( \$t, 'command_execution_failed' )", $source );
		self::assertStringNotContainsString( '$t->getMessage()', $source );
		self::assertStringContainsString( "The command could not be completed. Review the audit log.", $source );
	}

	public function test_f01_quarantine_routes_preserve_explicit_validation_but_not_raw_unexpected_messages(): void {
		$source = (string) file_get_contents( CB_CORE_DIR . 'src/Integrity/Rest/ScanController.php' );
		foreach ( [
			'cb_integrity_quarantine_failed',
			'cb_integrity_quarantine_inspect_failed',
			'cb_integrity_quarantine_restore_failed',
			'cb_integrity_quarantine_delete_failed',
			'cb_integrity_quarantine_note_failed',
			'cb_integrity_quarantine_state_failed',
		] as $code ) {
			self::assertStringContainsString( "unexpected_quarantine_error( \$throwable, '$code'", $source );
			self::assertStringNotContainsString( "new WP_Error( '$code', \$throwable->getMessage()", $source );
		}
		self::assertStringContainsString( "cb_integrity_quarantine_restore_confirmation', \$error->getMessage()", $source );
		self::assertStringContainsString( 'ThrowableBoundary::context( $throwable, $reason )', $source );
	}

	public function test_f01_bounded_audit_context_excludes_secrets(): void {
		$secret = 'token=example-private-value';
		$context = ThrowableBoundary::context( new RuntimeException( $secret ), 'quarantine_restore_failed' );

		self::assertSame( 'quarantine_restore_failed', $context['reason'] );
		self::assertSame( RuntimeException::class, $context['exception_class'] );
		self::assertStringNotContainsString( $secret, (string) wp_json_encode( $context ) );
	}
}
