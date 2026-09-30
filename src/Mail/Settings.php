<?php
declare(strict_types=1);
/**
 * Mail module settings repository.
 *
 * Delivery and presentation are intentionally independent persisted capabilities.
 * The module-level state is a computed projection: Mail is active whenever
 * delivery or designer is enabled. No aggregate state is persisted separately.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Mail;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'cb_core_mail_settings';
	public const PROVIDERS = [ 'brevo', 'smtp' ];
	public const ENCRYPTIONS = [ 'none', 'tls', 'ssl' ];
	public const RETENTION_DAYS = [ 7, 14, 30, 60, 90, 180, 365 ];

	public static function defaults(): array {
		return [
			'delivery_enabled'   => false,
			'designer_enabled'   => false,
			'provider'           => 'brevo',
			'from_email'         => sanitize_email( (string) get_option( 'admin_email', '' ) ),
			'from_name'          => sanitize_text_field( (string) get_bloginfo( 'name' ) ),
			'force_from_email'   => true,
			'force_from_name'    => true,
			'sender_identities'  => [],
			'brevo_api_key'      => '',
			'smtp_host'          => '',
			'smtp_port'          => 587,
			'smtp_encryption'    => 'tls',
			'smtp_auth'          => true,
			'smtp_username'      => '',
			'smtp_password'      => '',
			'smtp_auto_tls'      => true,
			'retention_days'     => 14,
		];
	}

	/** Canonical aggregate projection used by the module activation registry. */
	public static function enabled(): bool {
		$settings = self::all();
		return ! empty( $settings['delivery_enabled'] ) || ! empty( $settings['designer_enabled'] );
	}

	public static function delivery_enabled(): bool {
		return ! empty( self::all()['delivery_enabled'] );
	}

	public static function designer_enabled(): bool {
		return ! empty( self::all()['designer_enabled'] );
	}

	public static function all(): array {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, [] );
		$stored   = is_array( $stored ) ? array_intersect_key( $stored, $defaults ) : [];

		$settings = array_merge( $defaults, $stored );
		$settings['delivery_enabled'] = ! empty( $settings['delivery_enabled'] );
		$settings['designer_enabled'] = ! empty( $settings['designer_enabled'] );
		return $settings;
	}

	public static function save( array $settings ): bool {
		$previous_config = get_option( self::OPTION, null );
		$defaults        = self::defaults();

		$settings = array_merge( $defaults, array_intersect_key( $settings, $defaults ) );
		$settings['delivery_enabled'] = ! empty( $settings['delivery_enabled'] );
		$settings['designer_enabled'] = ! empty( $settings['designer_enabled'] );

		$config_changed = update_option( self::OPTION, $settings, false );

		$persisted_config = get_option( self::OPTION, null );
		if ( $settings !== $persisted_config ) {
			if ( null === $previous_config ) {
				delete_option( self::OPTION );
			} else {
				update_option( self::OPTION, $previous_config, false );
			}
			return false;
		}

		return $config_changed;
	}

	/**
	 * Return sanitized persisted overrides for registered sender identities.
	 *
	 * Unknown IDs remain stored so temporarily disabling an extension does not
	 * destroy its mail identity configuration. Only registered identities are
	 * exposed or used by SenderIdentityRegistry.
	 *
	 * @return array<string,array{email:string,name:string}>
	 */
	public static function sender_identity_overrides(): array {
		$settings = self::all();
		$stored   = is_array( $settings['sender_identities'] ?? null ) ? $settings['sender_identities'] : [];
		$out      = [];

		foreach ( $stored as $id => $identity ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $identity ) ) {
				continue;
			}
			$out[ $id ] = [
				'email' => sanitize_email( (string) ( $identity['email'] ?? '' ) ),
				'name'  => sanitize_text_field( (string) ( $identity['name'] ?? '' ) ),
			];
		}
		return $out;
	}

	/**
	 * Return a translation-free activation error code for the delivery runtime.
	 */
	public static function activation_error_code( ?array $settings = null ): string {
		$settings = is_array( $settings ) ? array_merge( self::defaults(), $settings ) : self::all();
		if ( ! is_email( (string) $settings['from_email'] ) ) {
			return 'invalid_from_email';
		}
		if ( 'brevo' === (string) $settings['provider'] && '' === Secrets::decrypt( (string) $settings['brevo_api_key'] ) ) {
			return 'missing_brevo_api_key';
		}
		if ( 'smtp' === (string) $settings['provider'] ) {
			if ( '' === trim( (string) $settings['smtp_host'] ) ) {
				return 'missing_smtp_host';
			}
			if ( ! empty( $settings['smtp_auth'] ) && ( '' === trim( (string) $settings['smtp_username'] ) || '' === Secrets::decrypt( (string) $settings['smtp_password'] ) ) ) {
				return 'missing_smtp_credentials';
			}
		}
		return '';
	}

	/** Human-readable delivery activation error. Call from init or later. */
	public static function activation_error( ?array $settings = null ): string {
		return match ( self::activation_error_code( $settings ) ) {
			'invalid_from_email'        => __( 'A valid From Email is required before Mail Delivery can be enabled.', 'core-blueprint' ),
			'missing_brevo_api_key'     => __( 'A Brevo API key is required before the Brevo transport can be enabled.', 'core-blueprint' ),
			'missing_smtp_host'         => __( 'An SMTP host is required before the SMTP transport can be enabled.', 'core-blueprint' ),
			'missing_smtp_credentials'  => __( 'SMTP username and password are required when authentication is enabled.', 'core-blueprint' ),
			default                     => '',
		};
	}

	public static function provider(): string {
		$provider = sanitize_key( (string) ( self::all()['provider'] ?? 'brevo' ) );
		return in_array( $provider, self::PROVIDERS, true ) ? $provider : 'brevo';
	}

	public static function brevo_api_key(): string {
		return Secrets::decrypt( (string) ( self::all()['brevo_api_key'] ?? '' ) );
	}

	public static function smtp_password(): string {
		return Secrets::decrypt( (string) ( self::all()['smtp_password'] ?? '' ) );
	}

	public static function retention_days(): int {
		$days = (int) ( self::all()['retention_days'] ?? 14 );
		return in_array( $days, self::RETENTION_DAYS, true ) ? $days : 14;
	}

	public static function provider_label( ?string $provider = null ): string {
		$provider = $provider ?: self::provider();
		return 'smtp' === $provider ? __( 'Generic SMTP', 'core-blueprint' ) : __( 'Brevo', 'core-blueprint' );
	}
}
