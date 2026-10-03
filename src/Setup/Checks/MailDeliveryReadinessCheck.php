<?php
declare(strict_types=1);
/**
 * Core Setup evidence for Mail Delivery configuration readiness.
 *
 * Secrets and sender addresses are represented only through one-way hashes or
 * presence booleans. Disabled Base delivery is a valid Not applicable state.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Mail\Admin\Page;
use CoreBlueprint\Core\Mail\ConflictDetector;
use CoreBlueprint\Core\Mail\Settings;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;
use CoreBlueprint\Core\Setup\Fingerprint;

defined( 'ABSPATH' ) || exit;

final class MailDeliveryReadinessCheck implements CheckInterface {

	public function id(): string { return 'mail-delivery-readiness'; }
	public function section(): string { return 'mail'; }
	public function label(): string { return 'Mail delivery readiness'; }
	public function kind(): string { return self::KIND_OPTIONAL; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Page::SLUG . '&tab=settings' ); }
	public function allows_later(): bool { return true; }

	public function evidence(): Evidence {
		try {
			$settings = Settings::all();
			$enabled = ! empty( $settings['delivery_enabled'] );

			if ( ! $enabled ) {
				return new Evidence(
					Evidence::HEALTH_OK,
					'mail.readiness-disabled',
					[ 'delivery_enabled' => false ],
					[ 'delivery_enabled' => false ]
				);
			}

			$provider = Settings::provider();
			$error = Settings::activation_error_code( $settings );
			$conflicts = ConflictDetector::active();
			ksort( $conflicts, SORT_STRING );
			$attention = '' !== $error || [] !== $conflicts;

			$sender_overrides = Settings::sender_identity_overrides();
			ksort( $sender_overrides, SORT_STRING );

			$fingerprint = [
				'delivery_enabled'       => true,
				'provider'               => $provider,
				'from_email_hash'        => Fingerprint::hash( [ 'value' => strtolower( (string) ( $settings['from_email'] ?? '' ) ) ] ),
				'from_name_hash'         => Fingerprint::hash( [ 'value' => (string) ( $settings['from_name'] ?? '' ) ] ),
				'force_from_email'       => ! empty( $settings['force_from_email'] ),
				'force_from_name'        => ! empty( $settings['force_from_name'] ),
				'sender_overrides_hash'  => Fingerprint::hash( [ 'identities' => $sender_overrides ] ),
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

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention ? 'mail.readiness-attention' : 'mail.readiness-ready',
				$fingerprint,
				[
					'delivery_enabled'       => true,
					'provider'               => $provider,
					'activation_error_code'  => $error,
					'conflicting_transports' => array_values( $conflicts ),
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'mail.readiness-unavailable' );
		}
	}

	public function allows_not_applicable( Evidence $evidence ): bool {
		return empty( $evidence->context()['delivery_enabled'] );
	}
}
