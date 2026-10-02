<?php
declare(strict_types=1);
/**
 * SettingsRegistry - canonical registration boundary for extension settings.
 *
 * Extensions keep ownership of their settings fields, validation and renderer.
 * Base owns routing, provenance, developer attribution, capability filtering,
 * semantic shared-UI requirements and the Settings Hub shell.
 *
 * A settings provider must refer to an extension already registered through
 * ExtensionRegistry. Developer identity and first-party provenance are derived
 * from that trusted inventory and cannot be overridden by provider metadata.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Admin;

use CB\Core\Admin\Pages\Settings as SettingsPage;
use CB\Core\ExtensionRegistry;
use CB\Core\UI\Icon;
defined( 'ABSPATH' ) || exit;

final class SettingsRegistry {

	public const GROUP_INFRASTRUCTURE     = 'infrastructure';
	public const GROUP_CONTENT_PUBLISHING = 'content-publishing';
	public const GROUP_COMMUNITY          = 'community';
	public const GROUP_BUSINESS           = 'business';
	public const GROUP_COMMERCE           = 'commerce';
	public const GROUP_OTHER              = 'other';

	private const GROUPS = [
		self::GROUP_INFRASTRUCTURE,
		self::GROUP_CONTENT_PUBLISHING,
		self::GROUP_COMMUNITY,
		self::GROUP_BUSINESS,
		self::GROUP_COMMERCE,
		self::GROUP_OTHER,
	];

	/** @var array<string,array<string,mixed>> */
	private static array $providers = [];

	private static bool $collected = false;

	/**
	 * Register one extension settings provider during `cb_core_register_settings`.
	 *
	 * Supported definition keys:
	 * - label          required caller-localized label
	 * - description    required caller-localized short description
	 * - group          one SettingsRegistry::GROUP_* value
	 * - capability     WordPress capability required to open the provider
	 * - renderer       callable that outputs the provider-owned settings body
	 * - icon           optional Base Icon semantic key
	 * - support_url    optional developer support URL
	 * - requirements   optional semantic `foundations` / `components` arrays
	 *
	 * Developer name, developer URL and first-party provenance are intentionally
	 * not accepted here. Base derives them from ExtensionRegistry/plugin headers.
	 *
	 * @param string              $extension_id Registered ExtensionRegistry ID.
	 * @param array<string,mixed> $definition   Provider metadata.
	 */
	public static function register( string $extension_id, array $definition ): bool {
		if ( ! doing_action( 'cb_core_register_settings' ) ) {
			self::diagnostic( 'Settings provider registration refused outside cb_core_register_settings.' );
			return false;
		}

		$extension_id = trim( $extension_id );
		if ( ! ExtensionRegistry::is_valid_id( $extension_id ) ) {
			self::diagnostic( 'Malformed settings provider extension id refused.' );
			return false;
		}
		if ( isset( self::$providers[ $extension_id ] ) ) {
			self::diagnostic( sprintf( 'Duplicate settings provider refused: %s.', $extension_id ) );
			return false;
		}

		$identity = ExtensionRegistry::identity( $extension_id );
		if ( null === $identity ) {
			self::diagnostic( sprintf( 'Settings provider %s has no trusted extension identity or developer metadata.', $extension_id ) );
			return false;
		}
		if ( true !== $identity['first_party'] && 0 === strcasecmp( 'Core Blueprint', (string) $identity['developer_name'] ) ) {
			self::diagnostic( sprintf( 'Settings provider %s cannot claim the reserved Core Blueprint developer identity.', $extension_id ) );
			return false;
		}

		$allowed_keys = [ 'label', 'description', 'group', 'capability', 'renderer', 'icon', 'support_url', 'requirements' ];
		if ( [] !== array_diff( array_keys( $definition ), $allowed_keys ) ) {
			self::diagnostic( sprintf( 'Settings provider %s contains unsupported metadata.', $extension_id ) );
			return false;
		}

		$label = isset( $definition['label'] ) && is_string( $definition['label'] ) ? trim( $definition['label'] ) : '';
		if ( '' === $label ) {
			self::diagnostic( sprintf( 'Settings provider %s requires a label.', $extension_id ) );
			return false;
		}

		$description = isset( $definition['description'] ) && is_string( $definition['description'] ) ? trim( $definition['description'] ) : '';
		if ( '' === $description ) {
			self::diagnostic( sprintf( 'Settings provider %s requires a description.', $extension_id ) );
			return false;
		}

		$group = isset( $definition['group'] ) && is_string( $definition['group'] ) ? sanitize_key( $definition['group'] ) : '';
		if ( ! in_array( $group, self::GROUPS, true ) ) {
			self::diagnostic( sprintf( 'Settings provider %s requested an unknown group.', $extension_id ) );
			return false;
		}

		$capability = isset( $definition['capability'] ) && is_string( $definition['capability'] ) ? trim( $definition['capability'] ) : '';
		if ( '' === $capability || sanitize_key( $capability ) !== $capability ) {
			self::diagnostic( sprintf( 'Settings provider %s has an invalid capability.', $extension_id ) );
			return false;
		}

		$renderer = $definition['renderer'] ?? null;
		if ( ! is_callable( $renderer ) ) {
			self::diagnostic( sprintf( 'Settings provider %s renderer is not callable.', $extension_id ) );
			return false;
		}

		$icon = isset( $definition['icon'] ) && is_string( $definition['icon'] ) ? sanitize_key( $definition['icon'] ) : '';
		if ( '' !== $icon && ! Icon::has( $icon ) ) {
			self::diagnostic( sprintf( 'Settings provider %s requested an unknown icon.', $extension_id ) );
			return false;
		}

		$support_url = isset( $definition['support_url'] ) && is_string( $definition['support_url'] )
			? esc_url_raw( trim( $definition['support_url'] ) )
			: '';
		if ( '' !== $support_url && ! self::valid_http_url( $support_url ) ) {
			self::diagnostic( sprintf( 'Settings provider %s has an invalid support URL.', $extension_id ) );
			return false;
		}

		$requirements = PageRegistry::normalize_semantic_requirements(
			is_array( $definition['requirements'] ?? null ) ? $definition['requirements'] : [],
			'settings:' . $extension_id
		);
		if ( null === $requirements ) {
			return false;
		}

		self::$providers[ $extension_id ] = [
			'id'             => $extension_id,
			'label'          => $label,
			'description'    => $description,
			'group'          => $group,
			'capability'     => $capability,
			'renderer'       => $renderer,
			'icon'           => $icon,
			'support_url'    => $support_url,
			'requirements'   => $requirements,
			'developer_name' => $identity['developer_name'],
			'developer_url'  => $identity['developer_url'],
			'first_party'    => true === $identity['first_party'],
		];
		return true;
	}

	/** Collect settings providers once per request. */
	public static function collect(): void {
		if ( self::$collected ) {
			return;
		}
		self::$collected = true;
		do_action( 'cb_core_register_settings' );
	}

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		self::collect();
		$providers = self::$providers;
		uasort( $providers, static fn( array $a, array $b ): int => strcasecmp( (string) $a['label'], (string) $b['label'] ) );
		return $providers;
	}

	/** @return array<string,array<string,mixed>> */
	public static function visible(): array {
		return array_filter(
			self::all(),
			static fn( array $provider ): bool => current_user_can( (string) $provider['capability'] )
		);
	}

	/** @return array<string,mixed>|null */
	public static function get( string $extension_id ): ?array {
		self::collect();
		return self::$providers[ $extension_id ] ?? null;
	}

	/**
	 * Build the canonical Settings Hub deep link for one extension.
	 *
	 * Additional query arguments such as a provider-owned `tab` are preserved,
	 * but callers cannot replace Base-owned `page` or `extension` routing keys.
	 *
	 * @param array<string,scalar> $query Additional provider query args.
	 */
	public static function url( string $extension_id, array $query = [] ): string {
		if ( ! ExtensionRegistry::is_valid_id( $extension_id ) ) {
			return '';
		}

		$args = [
			'page'      => SettingsPage::SLUG,
			'extension' => $extension_id,
		];
		foreach ( $query as $key => $value ) {
			if ( ! is_string( $key ) || in_array( $key, [ 'page', 'extension' ], true ) || sanitize_key( $key ) !== $key || ! is_scalar( $value ) ) {
				continue;
			}
			$args[ $key ] = (string) $value;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Return semantic requirements for the selected provider on a Settings Hub request.
	 *
	 * @internal Base folds these into the registered Settings page requirement set
	 *           before the canonical AdminAssetResolver runs, preserving cascade order.
	 *
	 * @return array{foundations:string[],components:string[]}
	 */
	public static function selected_requirements(): array {
		$empty = [ 'foundations' => [], 'components' => [] ];
		$page  = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing.
		if ( SettingsPage::SLUG !== $page ) {
			return $empty;
		}

		$extension_id = isset( $_GET['extension'] ) ? sanitize_key( (string) wp_unslash( $_GET['extension'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing.
		if ( '' === $extension_id ) {
			return $empty;
		}

		$provider = self::get( $extension_id );
		if ( null === $provider || ! current_user_can( (string) $provider['capability'] ) ) {
			return $empty;
		}

		return $provider['requirements'];
	}

	/** Reset request-local registry state for tests. */
	public static function _reset_for_testing(): void {
		self::$providers = [];
		self::$collected = false;
	}

	private static function valid_http_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		return in_array( strtolower( (string) $parts['scheme'] ), [ 'http', 'https' ], true );
	}

	private static function diagnostic( string $message ): void {
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( __METHOD__, esc_html( $message ), '1.0.0' );
			return;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Core Blueprint SettingsRegistry: ' . $message );
		}
	}
}
