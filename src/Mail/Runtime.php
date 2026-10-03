<?php
declare(strict_types=1);
/**
 * Runtime activation boundary for Core Blueprint Mail Delivery.
 *
 * No transport hooks are registered unless Delivery is enabled and no known
 * competing SMTP/mail transport plugin is active. Mail Designer is a separate
 * presentation capability and never activates transport hooks by itself.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail;

use CoreBlueprint\Core\Mail\Log\DeliveryLogger;
use CoreBlueprint\Core\Mail\Transport\BrevoTransport;
use CoreBlueprint\Core\Mail\Transport\SmtpTransport;

defined( 'ABSPATH' ) || exit;

final class Runtime {

	private static bool $active = false;

	public static function boot(): void {
		if ( ! DeliveryState::is_enabled() || ConflictDetector::has_conflict() || '' !== Settings::activation_error_code() ) {
			return;
		}

		$transports = apply_filters( 'core_blueprint_mail_transports', [
			BrevoTransport::slug() => BrevoTransport::class,
			SmtpTransport::slug()  => SmtpTransport::class,
		] );
		$transports = is_array( $transports ) ? $transports : [];
		$transport  = $transports[ Settings::provider() ] ?? null;

		if ( ! is_string( $transport ) || ! is_subclass_of( $transport, \CoreBlueprint\Core\Mail\Transport\TransportInterface::class ) ) {
			return;
		}

		self::$active = true;
		DeliveryLogger::boot();

		add_filter( 'wp_mail_from', [ __CLASS__, 'force_from_email' ], PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', [ __CLASS__, 'force_from_name' ], PHP_INT_MAX );

		$transport::boot();
	}

	public static function is_active(): bool {
		return self::$active;
	}

	public static function force_from_email( string $email ): string {
		$identity = SenderIdentityRegistry::current();
		if ( null !== $identity && is_email( $identity['email'] ) ) {
			return $identity['email'];
		}

		$settings = Settings::all();
		if ( empty( $settings['force_from_email'] ) ) {
			return $email;
		}

		$configured = sanitize_email( (string) $settings['from_email'] );
		return is_email( $configured ) ? $configured : $email;
	}

	public static function force_from_name( string $name ): string {
		$identity = SenderIdentityRegistry::current();
		if ( null !== $identity && '' !== $identity['name'] ) {
			return $identity['name'];
		}

		$settings = Settings::all();
		if ( empty( $settings['force_from_name'] ) ) {
			return $name;
		}

		$configured = sanitize_text_field( (string) $settings['from_name'] );
		return '' !== $configured ? $configured : $name;
	}
}
