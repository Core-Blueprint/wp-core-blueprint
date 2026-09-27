<?php
declare(strict_types=1);
/**
 * wp-admin transport and presentation for Environment Governance.
 *
 * Environment identity remains read-only and owned by WordPress. This class
 * only exposes the portable governance policy and a non-production admin
 * indicator for users who already hold the Safeguards authority.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Environment;

use CB\Core\Admin\Admin as CoreAdmin;
use CB\Core\Settings;
use WP_Admin_Bar;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const SAVE_ACTION = 'cb_core_save_environment_governance';
	public const NONCE_ACTION = 'cb_core_save_environment_governance';

	public static function boot(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, [ self::class, 'save' ] );

		if ( is_admin() && Governance::is_non_production() ) {
			add_action( 'admin_bar_menu', [ self::class, 'admin_bar_notice' ], 100 );
		}
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to change Environment Governance.', 'core-blueprint' ),
				esc_html__( 'Forbidden', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( self::NONCE_ACTION );

		$protect = isset( $_POST[ Governance::PROTECT_SEARCH_INDEXING ] )
			&& '1' === sanitize_key( wp_unslash( (string) $_POST[ Governance::PROTECT_SEARCH_INDEXING ] ) );

		$user  = wp_get_current_user();
		$actor = $user instanceof \WP_User && $user->exists()
			? 'admin:' . $user->user_login
			: 'admin:unknown';

		Settings::set_key(
			Governance::POLICY_KEY,
			[ Governance::PROTECT_SEARCH_INDEXING => $protect ],
			$actor
		);

		$saved = Governance::policy()[ Governance::PROTECT_SEARCH_INDEXING ] === $protect;
		$url   = add_query_arg(
			[
				'page'              => CoreAdmin::SAFEGUARDS_SLUG,
				'tab'               => 'environment',
				'environment_saved' => $saved ? 'success' : 'error',
			],
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	public static function admin_bar_notice( WP_Admin_Bar $bar ): void {
		if ( ! is_admin() || ! Governance::is_non_production() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$type = Governance::current_type();
		$bar->add_node(
			[
				'id'    => 'cb-core-environment-notice',
				'title' => sprintf(
					/* translators: %s: WordPress environment type */
					esc_html__( 'Environment: %s', 'core-blueprint' ),
					esc_html( self::environment_label( $type ) )
				),
				'href'  => admin_url( 'admin.php?page=' . CoreAdmin::SAFEGUARDS_SLUG . '&tab=environment' ),
				'meta'  => [
					'title' => __( 'WordPress environment. Click to review Environment Governance.', 'core-blueprint' ),
				],
			]
		);
	}

	public static function environment_label( ?string $type = null ): string {
		$type = $type ?? Governance::current_type();
		return match ( $type ) {
			'local'       => __( 'Local', 'core-blueprint' ),
			'development' => __( 'Development', 'core-blueprint' ),
			'staging'     => __( 'Staging', 'core-blueprint' ),
			default       => __( 'Production', 'core-blueprint' ),
		};
	}
}
