<?php
declare(strict_types=1);
/**
 * Core Setup evidence for Core Blueprint email notification routing.
 *
 * Recipient addresses are never persisted in Setup review metadata. Only
 * deterministic hashes and counts are used to detect routing drift.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\Admin\Pages\Preferences;
use CB\Core\EmailAlerts;
use CB\Core\Settings;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;
use CB\Core\Setup\Fingerprint;

defined( 'ABSPATH' ) || exit;

final class NotificationsPolicyCheck implements CheckInterface {

	private const GROUPS = [ 'audit', 'permissions', 'reports', 'integrity' ];

	public function id(): string { return 'notifications-policy'; }
	public function section(): string { return 'operations'; }
	public function label(): string { return 'Notifications'; }
	public function kind(): string { return self::KIND_DECISION; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Preferences::SLUG . '&tab=notifications' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$settings = Settings::get();
			$groups = [];
			$attention = false;
			$enabled_rules = 0;

			foreach ( self::GROUPS as $group ) {
				$group_settings = is_array( $settings[ $group ] ?? null ) ? $settings[ $group ] : [];
				$raw_alerts = is_array( $group_settings['email_alerts'] ?? null ) ? $group_settings['email_alerts'] : [];
				$alerts = [];
				foreach ( $raw_alerts as $key => $enabled ) {
					$key = sanitize_key( (string) $key );
					if ( '' === $key ) {
						continue;
					}
					$alerts[ $key ] = (bool) $enabled;
					$enabled_rules += $alerts[ $key ] ? 1 : 0;
				}
				ksort( $alerts, SORT_STRING );

				$recipient = EmailAlerts::resolve_recipients( $group );
				$recipient_count = self::recipient_count( $recipient );
				$group_enabled = in_array( true, $alerts, true );
				if ( $group_enabled && 0 === $recipient_count ) {
					$attention = true;
				}

				$groups[ $group ] = [
					'alerts'         => $alerts,
					'recipient_hash' => Fingerprint::hash( [ 'recipient' => strtolower( $recipient ) ] ),
					'recipient_count'=> $recipient_count,
				];
			}

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention ? 'notifications.routing-attention' : 'notifications.routing-ready',
				[ 'groups' => $groups ],
				[
					'enabled_rules' => $enabled_rules,
					'groups' => array_map(
						static fn( array $group ): array => [
							'enabled_rules'   => count( array_filter( $group['alerts'] ) ),
							'recipient_count' => $group['recipient_count'],
						],
						$groups
					),
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'notifications.unavailable' );
		}
	}

	private static function recipient_count( string $recipient ): int {
		if ( '' === trim( $recipient ) ) {
			return 0;
		}
		return count( array_filter( array_map( 'trim', explode( ',', $recipient ) ) ) );
	}
}
