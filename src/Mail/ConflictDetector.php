<?php
declare(strict_types=1);
/**
 * Detect mail-transport plugins that must not run beside Core Blueprint Mail.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail;

defined( 'ABSPATH' ) || exit;

final class ConflictDetector {

	private const KNOWN = [
		'fluent-smtp/fluent-smtp.php'       => 'FluentSMTP',
		'wp-mail-smtp/wp_mail_smtp.php'     => 'WP Mail SMTP',
		'post-smtp/postman-smtp.php'         => 'Post SMTP',
		'easy-wp-smtp/easy-wp-smtp.php'     => 'Easy WP SMTP',
		'smtp-mailer/main.php'               => 'SMTP Mailer',
	];

	/** @return array<string,string> plugin basename => label */
	public static function active(): array {
		$active = get_option( 'active_plugins', [] );
		$active = is_array( $active ) ? $active : [];

		$network = is_multisite() ? get_site_option( 'active_sitewide_plugins', [] ) : [];
		$network = is_array( $network ) ? array_keys( $network ) : [];

		$active = array_unique( array_merge( $active, $network ) );
		$out    = [];

		foreach ( self::KNOWN as $basename => $label ) {
			if ( in_array( $basename, $active, true ) ) {
				$out[ $basename ] = $label;
			}
		}

		// FluentSMTP exposes this constant as soon as its plugin file loads.
		// Keep this secondary check for non-standard plugin-directory names.
		if ( defined( 'FLUENTMAIL_PLUGIN_FILE' ) && ! in_array( 'FluentSMTP', $out, true ) ) {
			$out['fluent-smtp'] = 'FluentSMTP';
		}

		return $out;
	}

	public static function has_conflict(): bool {
		return ! empty( self::active() );
	}
}
