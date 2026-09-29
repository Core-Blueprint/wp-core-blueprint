<?php
declare(strict_types=1);
/**
 * Core Setup evidence for the site's outbound Mail delivery strategy.
 *
 * This check records the chosen ownership model only. Delivery configuration
 * readiness is evaluated separately so one transport problem cannot appear as
 * two Setup attention items.
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
			$enabled   = Settings::delivery_enabled();
			$provider  = Settings::provider();
			$conflicts = ConflictDetector::active();
			ksort( $conflicts, SORT_STRING );

			$external = $enabled ? [] : array_keys( $conflicts );
			$code = $enabled
				? 'mail.delivery-enabled'
				: ( [] !== $external ? 'mail.external-transport' : 'mail.delivery-disabled' );

			return new Evidence(
				Evidence::HEALTH_OK,
				$code,
				[
					'delivery_enabled'       => $enabled,
					'provider'               => $enabled ? $provider : '',
					'external_transports'    => $external,
				],
				[
					'delivery_enabled'    => $enabled,
					'provider'            => $enabled ? $provider : '',
					'external_transports' => array_values( $conflicts ),
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'mail.delivery-strategy-unavailable' );
		}
	}
}
