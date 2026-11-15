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

	/**
	 * Public legacy preview helper now uses synthetic samples too. Providers
	 * may have registered real configuration values in 'preview', which must
	 * never leak into a Browser/Designer preview or outgoing REST metadata.
	 *
	 * @return array<string,scalar|null>
	 */
	public static function preview_values(): array {
		return self::synthetic_preview_values();
	}


	/**
	 * Base-owned, fictional examples for the editor. Never forward provider
	 * preview values (which may contain live site or customer data) to a browser.
	 * Unknown admitted providers receive the same inert example value.
	 *
	 * @var array<string,string>
	 */
	private const SYNTHETIC_EXAMPLES = [
		'site.name' => 'Example Site',
		'site.url' => 'https://example.invalid',
		'site.admin_email' => 'admin@example.invalid',
		'user.display_name' => 'Jane Example',
		'user.login' => 'example-user',
		'user.email' => 'jane@example.invalid',
		'action.url' => 'https://example.invalid/action',
		'action.label' => 'Continue',
		'booking.customer_name' => 'Jane Example',
		'booking.customer_email' => 'jane@example.invalid',
		'booking.customer_phone' => '+31 6 12345678',
		'booking.customer_notes' => 'Example booking note',
		'booking.type_name' => 'Consultation',
		'booking.status' => 'confirmed',
		'booking.date' => '24 September 2026',
		'booking.start_time' => '10:00',
		'booking.end_time' => '11:00',
		'booking.timezone' => 'Europe/Amsterdam',
		'booking.location' => 'Online',
		'booking.manage_url' => 'https://example.invalid/manage-booking',
		'booking.manage_line' => 'Manage your booking: https://example.invalid/manage-booking',
		'booking.resource_names' => 'Example Resource',
	];
	private const CATALOG_LIMIT = 96;
	private const CORE_SHARED_IDS = [
		'site.name', 'site.url', 'site.admin_email', 'user.display_name',
		'user.login', 'user.email', 'action.url', 'action.label',
	];

	/** Safe sample values only; resolvers and provider preview values stay server-side. */
	public static function synthetic_preview_values(): array {
		self::boot();
		$out = [];
		foreach ( self::$definitions as $id => $_definition ) {
			$out[ $id ] = self::SYNTHETIC_EXAMPLES[ $id ] ?? 'Example value';
		}
		return $out;
	}

	/**
	 * Read-only admission projection for an authenticated active Mail resource.
	 * The resource-owning provider plus exact Base shared IDs are visible;
	 * no resolver, real preview, booking/learner context or live site setting.
	 *
	 * @return array{version:int,resourceKey:string,provider:string,items:list<array{id:string,provider:string,group:string,label:string,example:string}>}|null
	 */
	public static function catalog_for_resource( string $resource_key ): ?array {
		if ( ! current_user_can( 'manage_options' )
			|| ! \CoreBlueprint\Core\Mail\DesignerState::is_enabled() ) {
			return null;
		}
		$template = TemplateRegistry::get( $resource_key );
		if ( ! is_array( $template ) || ! is_string( $template['provider'] ?? null ) ) {
			return null;
		}
		$provider = $template['provider'];
		$items = [];
		foreach ( self::all() as $id => $definition ) {
			$owner = $definition['provider'];
			if ( 'core' === $owner ) {
				if ( ! in_array( $id, self::CORE_SHARED_IDS, true ) ) continue;
			} elseif ( $owner !== $provider ) {
				continue;
			}
			$label = $definition['label'];
			$group = $definition['group'];
			if ( strlen( $id ) > 96 || ! is_string( $label ) || '' === trim( $label )
				|| strlen( $label ) > 120 || ! is_string( $group ) || strlen( $group ) > 48 ) {
				continue;
			}
			if ( count( $items ) >= self::CATALOG_LIMIT ) break;
			$items[] = [
				'id' => $id, 'provider' => $owner, 'group' => $group,
				'label' => $label,
				'example' => self::SYNTHETIC_EXAMPLES[ $id ] ?? 'Example value',
			];
		}
		return [
			'version' => 1, 'resourceKey' => $resource_key,
			'provider' => $provider, 'items' => $items,
		];
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
