<?php
declare(strict_types=1);
/**
 * Admin POST handlers for Mail delivery settings, test mail and log maintenance.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail\Admin;

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Mail\ConflictDetector;
use CoreBlueprint\Core\Mail\DeliveryState;
use CoreBlueprint\Core\Mail\Log\Repository;
use CoreBlueprint\Core\Mail\Runtime;
use CoreBlueprint\Core\Mail\Secrets;
use CoreBlueprint\Core\Mail\Sender;
use CoreBlueprint\Core\Mail\SenderIdentityRegistry;
use CoreBlueprint\Core\Mail\Settings;
use CoreBlueprint\Core\Mail\TestContext;

defined( 'ABSPATH' ) || exit;

final class Actions {

	private const RESULT_PREFIX = 'cb_core_mail_result_';

	public static function boot(): void {
		add_action( 'admin_post_cb_core_mail_save', [ __CLASS__, 'save' ] );
		add_action( 'admin_post_cb_core_mail_test', [ __CLASS__, 'test' ] );
		add_action( 'admin_post_cb_core_mail_clear_log', [ __CLASS__, 'clear_log' ] );
	}

	public static function save(): void {
		self::guard( 'cb_core_mail_save' );
		self::guard_delivery_mutation();

		$current = Settings::all();
		$next    = $current;

		$provider                 = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'brevo';
		$next['provider']         = in_array( $provider, Settings::PROVIDERS, true ) ? $provider : 'brevo';
		$next['from_email']       = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '';
		$next['from_name']        = isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '';
		$next['force_from_email'] = isset( $_POST['force_from_email'] );
		$next['force_from_name']  = isset( $_POST['force_from_name'] );

		$identity_emails = isset( $_POST['sender_identity_email'] ) && is_array( $_POST['sender_identity_email'] )
			? wp_unslash( $_POST['sender_identity_email'] )
			: [];
		$identity_names = isset( $_POST['sender_identity_name'] ) && is_array( $_POST['sender_identity_name'] )
			? wp_unslash( $_POST['sender_identity_name'] )
			: [];
		$identity_overrides = Settings::sender_identity_overrides();

		foreach ( SenderIdentityRegistry::snapshot() as $id => $identity ) {
			$raw_email = isset( $identity_emails[ $id ] ) ? trim( (string) $identity_emails[ $id ] ) : '';
			$email     = sanitize_email( $raw_email );
			if ( '' !== $raw_email && ! is_email( $email ) ) {
				self::set_result(
					'error',
					sprintf(
						/* translators: %s: registered sender identity label */
						__( 'Enter a valid email address for the %s sender identity, or leave it blank to use the default sender.', 'core-blueprint' ),
						$identity['label']
					)
				);
				self::redirect( 'settings' );
			}

			$identity_overrides[ $id ] = [
				'email' => $email,
				'name'  => isset( $identity_names[ $id ] ) ? sanitize_text_field( (string) $identity_names[ $id ] ) : '',
			];
		}
		$next['sender_identities'] = $identity_overrides;

		$retention = isset( $_POST['retention_days'] ) ? (int) $_POST['retention_days'] : 14;
		$next['retention_days'] = in_array( $retention, Settings::RETENTION_DAYS, true ) ? $retention : 14;

		$brevo_key = isset( $_POST['brevo_api_key'] ) ? trim( (string) wp_unslash( $_POST['brevo_api_key'] ) ) : '';
		if ( isset( $_POST['clear_brevo_api_key'] ) ) {
			$next['brevo_api_key'] = '';
		} elseif ( '' !== $brevo_key ) {
			$next['brevo_api_key'] = Secrets::encrypt( $brevo_key );
		}

		$next['smtp_host']       = isset( $_POST['smtp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_host'] ) ) : '';
		$next['smtp_port']       = isset( $_POST['smtp_port'] ) ? max( 1, min( 65535, (int) $_POST['smtp_port'] ) ) : 587;
		$encryption              = isset( $_POST['smtp_encryption'] ) ? sanitize_key( wp_unslash( $_POST['smtp_encryption'] ) ) : 'tls';
		$next['smtp_encryption'] = in_array( $encryption, Settings::ENCRYPTIONS, true ) ? $encryption : 'tls';
		$next['smtp_auth']       = isset( $_POST['smtp_auth'] );
		$next['smtp_username']   = isset( $_POST['smtp_username'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_username'] ) ) : '';
		$next['smtp_auto_tls']   = isset( $_POST['smtp_auto_tls'] );

		$smtp_password = isset( $_POST['smtp_password'] ) ? (string) wp_unslash( $_POST['smtp_password'] ) : '';
		if ( isset( $_POST['clear_smtp_password'] ) ) {
			$next['smtp_password'] = '';
		} elseif ( '' !== $smtp_password ) {
			$next['smtp_password'] = Secrets::encrypt( $smtp_password );
		}

		$error = self::validate( $next );
		if ( '' !== $error ) {
			self::set_result( 'error', $error );
			self::redirect( 'settings' );
		}

		$changed = self::changed_keys( $current, $next );
		Settings::save( $next );

		if ( ! empty( $changed ) ) {
			AuditLog::log( 'mail_settings_updated', 'notice', [ 'changed' => $changed ] );
		}

		$message = ConflictDetector::has_conflict()
			? __( 'Mail delivery settings saved. Core Blueprint transport stays inactive until the conflicting mail plugin is disabled.', 'core-blueprint' )
			: __( 'Mail delivery settings saved.', 'core-blueprint' );

		self::set_result( 'success', $message );
		self::redirect( 'settings' );
	}

	public static function test(): void {
		self::guard( 'cb_core_mail_test' );

		$email = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			self::set_result( 'error', __( 'Enter a valid test email address.', 'core-blueprint' ) );
			self::redirect( 'test' );
		}
		if ( ! DeliveryState::is_enabled() ) {
			self::set_result( 'error', __( 'Enable Mail Delivery before sending a transport test email.', 'core-blueprint' ) );
			self::redirect( 'test' );
		}
		if ( ConflictDetector::has_conflict() || ! Runtime::is_active() ) {
			self::set_result( 'error', __( 'Core Blueprint Mail transport is inactive because the selected delivery provider is unavailable or another mail transport is active.', 'core-blueprint' ) );
			self::redirect( 'test' );
		}

		$identity_id = isset( $_POST['sender_identity'] ) ? sanitize_key( wp_unslash( $_POST['sender_identity'] ) ) : '';
		$identity_id = SenderIdentityRegistry::is_registered( $identity_id ) ? $identity_id : '';

		TestContext::begin();
		try {
			$sent = '' !== $identity_id
				? Sender::send(
					$identity_id,
					$email,
					__( 'Core Blueprint test email', 'core-blueprint' ),
					__( 'This test email confirms that Core Blueprint Mail can deliver messages from this WordPress site.', 'core-blueprint' )
				)
				: wp_mail(
					$email,
					__( 'Core Blueprint test email', 'core-blueprint' ),
					__( 'This test email confirms that Core Blueprint Mail can deliver messages from this WordPress site.', 'core-blueprint' )
				);
		} finally {
			TestContext::end();
		}

		AuditLog::log( 'mail_test_sent', $sent ? 'notice' : 'warning', [
			'provider'        => Settings::provider(),
			'sender_identity' => '' !== $identity_id ? $identity_id : 'default',
			'result'          => $sent ? 'success' : 'failed',
		] );

		self::set_result(
			$sent ? 'success' : 'error',
			$sent
				? __( 'Test email sent successfully. Check the Mail Log for the delivery record.', 'core-blueprint' )
				: __( 'Test email failed. Check the Mail Log for the provider error.', 'core-blueprint' )
		);
		self::redirect( 'test' );
	}

	public static function clear_log(): void {
		self::guard( 'cb_core_mail_clear_log' );
		$deleted = Repository::clear();
		AuditLog::log( 'mail_log_cleared', 'warning', [ 'deleted' => $deleted ] );
		self::set_result( 'success', sprintf(
			/* translators: %d: number of deleted mail log rows */
			_n( '%d mail log entry deleted.', '%d mail log entries deleted.', $deleted, 'core-blueprint' ),
			$deleted
		) );
		self::redirect( 'logs' );
	}

	public static function pull_result(): ?array {
		$key = self::RESULT_PREFIX . get_current_user_id();
		$result = get_transient( $key );
		delete_transient( $key );
		return is_array( $result ) ? $result : null;
	}

	private static function validate( array $settings ): string {
		return Settings::activation_error( $settings );
	}

	private static function changed_keys( array $before, array $after ): array {
		$secret_keys = [ 'brevo_api_key', 'smtp_password' ];
		$out = [];
		foreach ( array_keys( $after ) as $key ) {
			if ( in_array( $key, $secret_keys, true ) ) {
				if ( (string) ( $before[ $key ] ?? '' ) !== (string) ( $after[ $key ] ?? '' ) ) {
					$out[] = $key . '_changed';
				}
				continue;
			}
			if ( ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage mail settings.', 'core-blueprint' ),
				esc_html__( 'Forbidden', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}
		check_admin_referer( $action );
	}

	private static function guard_delivery_mutation(): void {
		if ( DeliveryState::is_enabled() ) {
			return;
		}
		wp_die(
			esc_html__( 'Mail Delivery is disabled. Enable it from Mail Overview before changing delivery settings.', 'core-blueprint' ),
			esc_html__( 'Mail Delivery disabled', 'core-blueprint' ),
			[ 'response' => 409 ]
		);
	}

	private static function set_result( string $type, string $message ): void {
		set_transient(
			self::RESULT_PREFIX . get_current_user_id(),
			[ 'type' => $type, 'message' => $message ],
			MINUTE_IN_SECONDS
		);
	}

	private static function redirect( string $tab ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . Page::SLUG . '&tab=' . sanitize_key( $tab ) ) );
		exit;
	}
}
