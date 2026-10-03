<?php
declare(strict_types=1);
/**
 * Generic SMTP transport configuration for WordPress' PHPMailer instance.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail\Transport;

use CoreBlueprint\Core\Mail\Settings;

use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

final class SmtpTransport implements TransportInterface {

	public static function slug(): string {
		return 'smtp';
	}

	public static function boot(): void {
		add_action( 'phpmailer_init', [ __CLASS__, 'configure' ], PHP_INT_MAX );
	}

	public static function configure( PHPMailer $phpmailer ): void {
		$settings = Settings::all();

		$phpmailer->isSMTP();
		$phpmailer->Host       = trim( (string) $settings['smtp_host'] );
		$phpmailer->Port       = max( 1, min( 65535, (int) $settings['smtp_port'] ) );
		$phpmailer->SMTPAuth   = ! empty( $settings['smtp_auth'] );
		$phpmailer->SMTPAutoTLS = ! empty( $settings['smtp_auto_tls'] );
		$phpmailer->Timeout    = 15;

		$encryption = sanitize_key( (string) $settings['smtp_encryption'] );
		$phpmailer->SMTPSecure = in_array( $encryption, [ 'tls', 'ssl' ], true ) ? $encryption : '';

		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = (string) $settings['smtp_username'];
			$phpmailer->Password = Settings::smtp_password();
		} else {
			$phpmailer->Username = '';
			$phpmailer->Password = '';
		}
	}
}
