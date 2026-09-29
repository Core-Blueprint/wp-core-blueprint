<?php
declare(strict_types=1);
/**
 * Core Setup evidence for the site's outbound Mail delivery strategy.
 *
 * A disabled Base delivery runtime is a valid reviewed choice, especially when
 * another supported transport plugin is active. Attention is reserved for a
 * Base delivery runtime that is enabled but cannot safely become active.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\Mail\Admin\Page;
use CB\Core\Mail\ConflictDetector;
use CB\Core\Mail\Settings;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;
use CB\Core\Setup\Fingerprint;

defined( 'ABSPATH' ) || exit;

final class MailDeliveryStrategyCheck implements CheckInterface {

	public function id(): string { return 'mail-delivery-strategy'; }
	public function section(): string { return 'mail'; }
	public function label(): string { return 'Mail delivery strategy'; }
	public function kind(): string { return self::KIND_DECISION; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Page::SLUG . '&tab=settings' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$settings  = Settings::all();
			$enabled   = ! empty( $settings['delivery_enabled'] );
			$provider  = Settings::provider();
			$error     = Settings::activation_error_code( $settings );
			$conflicts = ConflictDetector::active();
			ksort( $conflicts, SORT_STRING );
			$attention = $enabled && ( '' !== $error || [] !== $conflicts );

			$sender_overrides = Settings::sender_identity_overrides();
			ksort( $sender_overrides, SORT_STRING );

			$fingerprint = [
				'delivery_enabled'       => $enabled,
				'provider'               => $provider,
				'from_email_hash'        => Fingerprint::hash( [ 'value' => strtolower( (string) ( $settings['from_email'] ?? '' ) ) ] ),
				'from_name_hash'         => Fingerprint::hash( [ 'value' => (string) ( $settings['from_name'] ?? '' ) ] ),
				'force_from_email'       => ! empty( $settings['force_from_email'] ),
				'force_from_name'        => ! empty( $settings['force_from_name'] ),
				'sender_overrides_hash'  => Fingerprint::hash( [ 'identities' => $sender_overrides ] ),
				'retention_days'         => Settings::retention_days(),
				'activation_error_code'  => $error,
				'conflicting_transports' => array_keys( $conflicts ),
			];

			if ( 'smtp' === $provider ) {
				$fingerprint['smtp'] = [
					'host_hash'        => Fingerprint::hash( [ 'value' => strtolower( trim( (string) ( $settings['smtp_host'] ?? '' ) ) ) ] ),
					'port'             => (int) ( $settings['smtp_port'] ?? 0 ),
					'encryption'       => (string) ( $settings['smtp_encryption'] ?? '' ),
					'auth'             => ! empty( $settings['smtp_auth'] ),
					'username_hash'    => Fingerprint::hash( [ 'value' => (string) ( $settings['smtp_username'] ?? '' ) ] ),
					'password_present' => '' !== trim( (string) ( $settings['smtp_password'] ?? '' ) ),
					'auto_tls'         => ! empty( $settings['smtp_auto_tls'] ),
				];
			} else {
				$fingerprint['brevo'] = [
					'api_key_present' => '' !== trim( (string) ( $settings['brevo_api_key'] ?? '' ) ),
				];
			}

			$code = 'mail.delivery-disabled';
			if ( $attention ) {
				$code = 'mail.delivery-attention';
			} elseif ( $enabled ) {
				$code = 'mail.delivery-enabled';
			} elseif ( [] !== $conflicts ) {
				$code = 'mail.external-transport';
			}

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$code,
				$fingerprint,
				[
					'delivery_enabled'       => $enabled,
					'provider'               => $provider,
					'activation_error_code'  => $error,
					'conflicting_transports' => array_values( $conflicts ),
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'mail.delivery-unavailable' );
		}
	}
}
