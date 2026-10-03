<?php
declare(strict_types=1);
/**
 * Public binding registry for Mail Designer dynamic values.
 *
 * Bindings are scalar-only. Providers resolve their own domain context; the
 * Design Foundation and renderer never inspect WooCommerce/contracts/etc.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Mail\Designer;

defined( 'ABSPATH' ) || exit;

final class BindingRegistry {
	/** @var array<string,array{id:string,provider:string,group:string,label:string,preview:scalar|null,resolver:callable}> */
	private static array $definitions = [];
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		self::register_core();
		/** Fires once so extensions can register Mail Designer bindings. */
		do_action( 'core_blueprint_register_mail_bindings' );
	}

	/**
	 * @param array{id:string,provider:string,group:string,label:string,preview?:scalar|null,resolver:callable} $definition
	 */
	public static function register( array $definition ): bool {
		$id = isset( $definition['id'] ) ? trim( (string) $definition['id'] ) : '';
		$provider = isset( $definition['provider'] ) ? sanitize_key( (string) $definition['provider'] ) : '';
		$group = isset( $definition['group'] ) ? sanitize_key( (string) $definition['group'] ) : '';
		$label = isset( $definition['label'] ) ? sanitize_text_field( (string) $definition['label'] ) : '';
		$resolver = $definition['resolver'] ?? null;
		$preview = $definition['preview'] ?? null;

		if (
			1 !== preg_match( '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/', $id )
			|| '' === $provider
			|| '' === $group
			|| '' === $label
			|| ! is_callable( $resolver )
			|| ( null !== $preview && ! is_scalar( $preview ) )
			|| isset( self::$definitions[ $id ] )
		) {
			return false;
		}

		self::$definitions[ $id ] = [
			'id'       => $id,
			'provider' => $provider,
			'group'    => $group,
			'label'    => $label,
			'preview'  => $preview,
			'resolver' => $resolver,
		];
		return true;
	}

	/** @return array<string,array{id:string,provider:string,group:string,label:string,preview:scalar|null,resolver:callable}> */
	public static function all(): array {
		self::boot();
		return self::$definitions;
	}

	/** @param array<string,mixed> $context @return array<string,scalar|null> */
	public static function resolve( array $context ): array {
		self::boot();
		$out = [];
		foreach ( self::$definitions as $id => $definition ) {
			try {
				$value = call_user_func( $definition['resolver'], $context );
			} catch ( \Throwable $exception ) {
				$value = null;
			}
			$out[ $id ] = null === $value || is_scalar( $value ) ? $value : null;
		}
		return $out;
	}

	/** @return array<string,scalar|null> */
	public static function preview_values(): array {
		self::boot();
		$out = [];
		foreach ( self::$definitions as $id => $definition ) {
			$out[ $id ] = $definition['preview'];
		}
		return $out;
	}

	private static function register_core(): void {
		$definitions = [
			[ 'id' => 'site.name', 'group' => 'site', 'label' => __( 'Site name', 'core-blueprint' ), 'preview' => get_bloginfo( 'name' ), 'resolver' => static fn ( array $context ): string => (string) ( $context['site']['name'] ?? get_bloginfo( 'name' ) ) ],
			[ 'id' => 'site.url', 'group' => 'site', 'label' => __( 'Site URL', 'core-blueprint' ), 'preview' => home_url( '/' ), 'resolver' => static fn ( array $context ): string => (string) ( $context['site']['url'] ?? home_url( '/' ) ) ],
			[ 'id' => 'site.admin_email', 'group' => 'site', 'label' => __( 'Site admin email', 'core-blueprint' ), 'preview' => get_option( 'admin_email', '' ), 'resolver' => static fn ( array $context ): string => (string) ( $context['site']['admin_email'] ?? get_option( 'admin_email', '' ) ) ],
			[ 'id' => 'user.display_name', 'group' => 'user', 'label' => __( 'User display name', 'core-blueprint' ), 'preview' => __( 'Jane Example', 'core-blueprint' ), 'resolver' => static fn ( array $context ): string => (string) ( $context['user']['display_name'] ?? '' ) ],
			[ 'id' => 'user.login', 'group' => 'user', 'label' => __( 'Username', 'core-blueprint' ), 'preview' => 'jane', 'resolver' => static fn ( array $context ): string => (string) ( $context['user']['login'] ?? '' ) ],
			[ 'id' => 'user.email', 'group' => 'user', 'label' => __( 'User email', 'core-blueprint' ), 'preview' => 'jane@example.com', 'resolver' => static fn ( array $context ): string => (string) ( $context['user']['email'] ?? '' ) ],
			[ 'id' => 'action.url', 'group' => 'action', 'label' => __( 'Action URL', 'core-blueprint' ), 'preview' => home_url( '/example-action/' ), 'resolver' => static fn ( array $context ): string => (string) ( $context['action']['url'] ?? '' ) ],
			[ 'id' => 'action.label', 'group' => 'action', 'label' => __( 'Action label', 'core-blueprint' ), 'preview' => __( 'Continue', 'core-blueprint' ), 'resolver' => static fn ( array $context ): string => (string) ( $context['action']['label'] ?? '' ) ],
		];

		foreach ( $definitions as $definition ) {
			$definition['provider'] = 'core';
			self::register( $definition );
		}
	}

	/** @internal tests */
	public static function _reset_for_testing(): void {
		self::$definitions = [];
		self::$booted = false;
	}

	private function __construct() {}
}
