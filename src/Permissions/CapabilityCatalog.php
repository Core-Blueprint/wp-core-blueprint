<?php
declare(strict_types=1);
/**
 * CapabilityCatalog
 *
 * Machine-readable catalog for WordPress and Core Blueprint capabilities.
 * WordPress itself stores primitive capability names but no human-facing
 * metadata. The User Roles editor uses this catalog to group and explain
 * capabilities without hard-coding knowledge of sibling plugins.
 *
 * Sibling plugins can enrich the catalog with the `core_blueprint_capability_catalog`
 * filter. Capabilities already present on a WordPress role are discovered
 * automatically and receive inferred metadata when no explicit registration
 * exists.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Permissions;

use CoreBlueprint\Core\AdminNotices\Capabilities as AdminNoticeCapabilities;

defined( 'ABSPATH' ) || exit;

final class CapabilityCatalog {

	/**
	 * Known WordPress primitive capabilities used only for source grouping.
	 * Custom post-type capabilities intentionally fall through to "Other"
	 * unless their owning plugin registers richer metadata through the filter.
	 *
	 * @var string[]
	 */
	private const WORDPRESS_CAPS = [
		'activate_plugins', 'create_users', 'delete_others_pages', 'delete_others_posts',
		'delete_pages', 'delete_plugins', 'delete_posts', 'delete_private_pages',
		'delete_private_posts', 'delete_published_pages', 'delete_published_posts',
		'delete_themes', 'delete_users', 'edit_dashboard', 'edit_files',
		'edit_others_pages', 'edit_others_posts', 'edit_pages', 'edit_plugins',
		'edit_posts', 'edit_private_pages', 'edit_private_posts', 'edit_published_pages',
		'edit_published_posts', 'edit_theme_options', 'edit_themes', 'edit_users',
		'export', 'import', 'install_languages', 'install_plugins', 'install_themes',
		'list_users', 'manage_categories', 'manage_links', 'manage_options',
		'moderate_comments', 'promote_users', 'publish_pages', 'publish_posts',
		'read', 'read_private_pages', 'read_private_posts', 'remove_users',
		'resume_plugins', 'resume_themes', 'switch_themes', 'unfiltered_html',
		'unfiltered_upload', 'update_core', 'update_plugins', 'update_themes',
		'upload_files', 'customize',
	];

	/**
	 * Full normalized catalog keyed by primitive capability.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$catalog = self::core_blueprint_catalog();

		$wp_roles = wp_roles();
		foreach ( (array) $wp_roles->roles as $role ) {
			$caps = isset( $role['capabilities'] ) && is_array( $role['capabilities'] )
				? $role['capabilities']
				: [];

			foreach ( $caps as $cap => $granted ) {
				if ( ! $granted || isset( $catalog[ $cap ] ) ) {
					continue;
				}
				$catalog[ $cap ] = self::inferred_metadata( (string) $cap );
			}
		}

		/**
		 * Filter: core_blueprint_capability_catalog
		 *
		 * Lets sibling plugins register labels, descriptions and grouping for
		 * their primitive capabilities. Array keys are capability names.
		 * Supported fields: label, group, source, description, policy_grant.
		 *
		 * @param array<string,array<string,mixed>> $catalog
		 */
		$catalog = (array) apply_filters( 'core_blueprint_capability_catalog', $catalog );

		$normalized = [];
		foreach ( $catalog as $cap => $meta ) {
			$cap = sanitize_key( (string) $cap );
			if ( '' === $cap ) {
				continue;
			}
			$meta = is_array( $meta ) ? $meta : [];
			$inferred = self::inferred_metadata( $cap );
			$normalized[ $cap ] = [
				'capability'   => $cap,
				'label'        => (string) ( $meta['label'] ?? $inferred['label'] ),
				'group'        => (string) ( $meta['group'] ?? $inferred['group'] ),
				'source'       => (string) ( $meta['source'] ?? $inferred['source'] ),
				'description'  => (string) ( $meta['description'] ?? '' ),
				'policy_grant' => ! empty( $meta['policy_grant'] ),
			];
		}

		ksort( $normalized, SORT_NATURAL | SORT_FLAG_CASE );
		return $normalized;
	}

	/**
	 * Core Blueprint-owned capabilities with stable metadata.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	/** Whether translated presentation metadata is safe to resolve. */
	private static function i18n_ready(): bool {
		return did_action( 'init' ) > 0 || doing_action( 'init' );
	}

	private static function core_blueprint_catalog(): array {
		$policy = Caps::policy_grants();

		return [
			'cb_view_reports' => [
				'label' => ( self::i18n_ready() ? __( 'View reports', 'core-blueprint' ) : 'View reports' ),
				'group' => ( self::i18n_ready() ? __( 'Core Blueprint', 'core-blueprint' ) : 'Core Blueprint' ),
				'source' => 'Core Blueprint',
				'description' => ( self::i18n_ready() ? __( 'View the Core Blueprint Reports page and generated report history.', 'core-blueprint' ) : 'View the Core Blueprint Reports page and generated report history.' ),
			],
			'cb_view_permissions' => [
				'label' => ( self::i18n_ready() ? __( 'View permissions', 'core-blueprint' ) : 'View permissions' ),
				'group' => ( self::i18n_ready() ? __( 'Core Blueprint', 'core-blueprint' ) : 'Core Blueprint' ),
				'source' => 'Core Blueprint',
				'description' => ( self::i18n_ready() ? __( 'View Core Blueprint permission governance and related safeguards.', 'core-blueprint' ) : 'View Core Blueprint permission governance and related safeguards.' ),
			],
			'cb_manage_reports' => [
				'label' => ( self::i18n_ready() ? __( 'Manage reports', 'core-blueprint' ) : 'Manage reports' ),
				'group' => ( self::i18n_ready() ? __( 'Core Blueprint', 'core-blueprint' ) : 'Core Blueprint' ),
				'source' => 'Core Blueprint',
				'description' => ( self::i18n_ready() ? __( 'Generate and manage Core Blueprint reports.', 'core-blueprint' ) : 'Generate and manage Core Blueprint reports.' ),
				'policy_grant' => ! empty( $policy['cb_manage_reports'] ),
			],
			'cb_manage_branding' => [
				'label' => ( self::i18n_ready() ? __( 'Manage report branding', 'core-blueprint' ) : 'Manage report branding' ),
				'group' => ( self::i18n_ready() ? __( 'Core Blueprint', 'core-blueprint' ) : 'Core Blueprint' ),
				'source' => 'Core Blueprint',
				'description' => ( self::i18n_ready() ? __( 'Change report branding settings.', 'core-blueprint' ) : 'Change report branding settings.' ),
			],
			'cb_manage_permissions' => [
				'label' => ( self::i18n_ready() ? __( 'Manage Core Blueprint permissions', 'core-blueprint' ) : 'Manage Core Blueprint permissions' ),
				'group' => ( self::i18n_ready() ? __( 'Core Blueprint', 'core-blueprint' ) : 'Core Blueprint' ),
				'source' => 'Core Blueprint',
				'description' => ( self::i18n_ready() ? __( 'Manage Core Blueprint operator and permission governance settings.', 'core-blueprint' ) : 'Manage Core Blueprint operator and permission governance settings.' ),
			],
			'cb_manage_roles' => [
				'label' => ( self::i18n_ready() ? __( 'Manage user roles', 'core-blueprint' ) : 'Manage user roles' ),
				'group' => ( self::i18n_ready() ? __( 'Core Blueprint', 'core-blueprint' ) : 'Core Blueprint' ),
				'source' => 'Core Blueprint',
				'description' => ( self::i18n_ready() ? __( 'Create and manage WordPress roles and their primitive capabilities.', 'core-blueprint' ) : 'Create and manage WordPress roles and their primitive capabilities.' ),
			],
			AdminNoticeCapabilities::MANAGE => [
				'label' => ( self::i18n_ready() ? __( 'Manage admin notices', 'core-blueprint' ) : 'Manage admin notices' ),
				'group' => ( self::i18n_ready() ? __( 'Core Blueprint', 'core-blueprint' ) : 'Core Blueprint' ),
				'source' => 'Core Blueprint',
				'description' => ( self::i18n_ready() ? __( 'Manage which audiences see supported WordPress admin notice sources.', 'core-blueprint' ) : 'Manage which audiences see supported WordPress admin notice sources.' ),
			],
			'cb_manage_integrity' => [
				'label' => ( self::i18n_ready() ? __( 'Run integrity scans', 'core-blueprint' ) : 'Run integrity scans' ),
				'group' => ( self::i18n_ready() ? __( 'Core Blueprint', 'core-blueprint' ) : 'Core Blueprint' ),
				'source' => 'Core Blueprint',
				'description' => ( self::i18n_ready() ? __( 'Run Core Scanner and review its current integrity findings.', 'core-blueprint' ) : 'Run Core Scanner and review its current integrity findings.' ),
				'policy_grant' => ! empty( $policy['cb_manage_integrity'] ),
			],
			'cb_manage_integrity_policy' => [
				'label' => ( self::i18n_ready() ? __( 'Manage integrity trust policy', 'core-blueprint' ) : 'Manage integrity trust policy' ),
				'group' => ( self::i18n_ready() ? __( 'Core Blueprint', 'core-blueprint' ) : 'Core Blueprint' ),
				'source' => 'Core Blueprint',
				'description' => ( self::i18n_ready() ? __( 'Approve integrity baselines and manage Core Scanner trust, configuration, evidence, and notification policy.', 'core-blueprint' ) : 'Approve integrity baselines and manage Core Scanner trust, configuration, evidence, and notification policy.' ),
			],
			'cb_manage_notes' => [
				'label' => ( self::i18n_ready() ? __( 'Manage notes', 'core-blueprint' ) : 'Manage notes' ),
				'group' => ( self::i18n_ready() ? __( 'Core Blueprint', 'core-blueprint' ) : 'Core Blueprint' ),
				'source' => 'Core Blueprint',
				'description' => ( self::i18n_ready() ? __( 'View and manage Core Blueprint Notes.', 'core-blueprint' ) : 'View and manage Core Blueprint Notes.' ),
			],
			'cb_use_cli' => [
				'label' => ( self::i18n_ready() ? __( 'Use CLI tools', 'core-blueprint' ) : 'Use CLI tools' ),
				'group' => ( self::i18n_ready() ? __( 'Core Blueprint', 'core-blueprint' ) : 'Core Blueprint' ),
				'source' => 'Core Blueprint',
				'description' => ( self::i18n_ready() ? __( 'See Core Blueprint CLI documentation and CLI-related operator UI.', 'core-blueprint' ) : 'See Core Blueprint CLI documentation and CLI-related operator UI.' ),
			],
			'cb_core_hud_use' => [
				'label' => ( self::i18n_ready() ? __( 'Use Core Blueprint HUD', 'core-blueprint' ) : 'Use Core Blueprint HUD' ),
				'group' => ( self::i18n_ready() ? __( 'Core Blueprint', 'core-blueprint' ) : 'Core Blueprint' ),
				'source' => 'Core Blueprint',
				'description' => ( self::i18n_ready() ? __( 'Use the Core Blueprint HUD. Users with manage_options receive this dynamically by default.', 'core-blueprint' ) : 'Use the Core Blueprint HUD. Users with manage_options receive this dynamically by default.' ),
				'policy_grant' => true,
			],
		];
	}

	/**
	 * Create readable fallback metadata for an unregistered capability.
	 *
	 * @return array{label:string,group:string,source:string,description:string,policy_grant:bool}
	 */
	private static function inferred_metadata( string $cap ): array {
		$source = ( self::i18n_ready() ? __( 'Other', 'core-blueprint' ) : 'Other' );
		$group  = ( self::i18n_ready() ? __( 'Other', 'core-blueprint' ) : 'Other' );

		if ( str_starts_with( $cap, 'cb_' ) ) {
			$source = 'Core Blueprint';
			$group  = ( self::i18n_ready() ? __( 'Core Blueprint', 'core-blueprint' ) : 'Core Blueprint' );
		} elseif ( in_array( $cap, self::WORDPRESS_CAPS, true ) ) {
			$source = 'WordPress';
			$group  = ( self::i18n_ready() ? __( 'WordPress', 'core-blueprint' ) : 'WordPress' );
		} elseif (
			str_contains( $cap, 'woocommerce' ) ||
			str_contains( $cap, 'shop_order' ) ||
			str_contains( $cap, 'shop_coupon' ) ||
			str_contains( $cap, 'product' )
		) {
			$source = 'WooCommerce';
			$group  = 'WooCommerce';
		}

		$label = ucfirst( str_replace( '_', ' ', $cap ) );

		return [
			'label'        => $label,
			'group'        => $group,
			'source'       => $source,
			'description'  => '',
			'policy_grant' => false,
		];
	}
}
