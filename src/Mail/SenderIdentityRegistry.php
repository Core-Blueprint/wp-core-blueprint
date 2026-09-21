<?php
declare(strict_types=1);
/**
 * Controlled sender identity registry for Core Blueprint extensions.
 *
 * Registration is a public Core API v1 contract. Base owns transport,
 * validation and persisted sender overrides; extensions only declare an
 * identity slot and request it when sending mail.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Mail;

defined( 'ABSPATH' ) || exit;

final class SenderIdentityRegistry {

	/** @var array<string,array{id:string,label:string,description:string,default_email:string,default_name:string}> */
	private static array $definitions = [];

	/**
	 * Register one sender identity definition.
	 *
	 * @param array{id?:string,label?:string,description?:string,default_email?:string,default_name?:string} $definition
	 */
	public static function register( array $definition ): bool {
		$raw_id = trim( (string) ( $definition['id'] ?? '' ) );
		$id     = sanitize_key( $raw_id );
		if ( $raw_id !== $id || ! self::valid_id( $id ) || isset( self::$definitions[ $id ] ) ) {
			return false;
		}

		$label = sanitize_text_field( (string) ( $definition['label'] ?? '' ) );
		if ( '' === $label ) {
			return false;
		}

		$default_email = sanitize_email( (string) ( $definition['default_email'] ?? '' ) );
		if ( '' !== $default_email && ! is_email( $default_email ) ) {
			return false;
		}

		self::$definitions[ $id ] = [
			'id'            => $id,
			'label'         => $label,
			'description'   => sanitize_text_field( (string) ( $definition['description'] ?? '' ) ),
			'default_email' => $default_email,
			'default_name'  => sanitize_text_field( (string) ( $definition['default_name'] ?? '' ) ),
		];
		return true;
	}

	public static function is_registered( string $id ): bool {
		return isset( self::$definitions[ sanitize_key( $id ) ] );
	}

	/**
	 * Return a resolved registered identity, or null for an unknown ID.
	 *
	 * @return null|array{id:string,label:string,description:string,email:string,name:string,default_email:string,default_name:string}
	 */
	public static function get( string $id ): ?array {
		$id = sanitize_key( $id );
		if ( ! isset( self::$definitions[ $id ] ) ) {
			return null;
		}

		$definition = self::$definitions[ $id ];
		$overrides  = Settings::sender_identity_overrides();
		$override   = is_array( $overrides[ $id ] ?? null ) ? $overrides[ $id ] : [];
		$default    = self::default_identity();

		$email = sanitize_email( (string) ( $override['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			$email = is_email( $definition['default_email'] ) ? $definition['default_email'] : $default['email'];
		}

		$name = sanitize_text_field( (string) ( $override['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = '' !== $definition['default_name'] ? $definition['default_name'] : $default['name'];
		}

		return [
			'id'            => $id,
			'label'         => $definition['label'],
			'description'   => $definition['description'],
			'email'         => $email,
			'name'          => $name,
			'default_email' => $definition['default_email'],
			'default_name'  => $definition['default_name'],
		];
	}

	/**
	 * Return all registered identities with their effective sender values.
	 *
	 * @return array<string,array{id:string,label:string,description:string,email:string,name:string,default_email:string,default_name:string}>
	 */
	public static function snapshot(): array {
		$out = [];
		foreach ( array_keys( self::$definitions ) as $id ) {
			$identity = self::get( $id );
			if ( null !== $identity ) {
				$out[ $id ] = $identity;
			}
		}
		return $out;
	}

	/** @return array{id:string,label:string,description:string,email:string,name:string} */
	public static function default_identity(): array {
		$settings = Settings::all();
		return [
			'id'          => 'default',
			'label'       => 'Default',
			'description' => '',
			'email'       => sanitize_email( (string) ( $settings['from_email'] ?? '' ) ),
			'name'        => sanitize_text_field( (string) ( $settings['from_name'] ?? '' ) ),
		];
	}

	/**
	 * Resolve the sender active for the current scoped send operation.
	 *
	 * @return null|array{id:string,label:string,description:string,email:string,name:string,default_email:string,default_name:string}
	 */
	public static function current(): ?array {
		$resolved = SenderContext::current_resolved();
		if ( null !== $resolved ) {
			return $resolved;
		}

		$id = SenderContext::current();
		return '' !== $id ? self::get( $id ) : null;
	}

	private static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/', $id );
	}
}
