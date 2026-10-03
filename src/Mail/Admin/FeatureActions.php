<?php
declare(strict_types=1);
/**
 * Admin POST handler for independent Mail capability switches.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail\Admin;

use CoreBlueprint\Core\Mail\DeliveryState;
use CoreBlueprint\Core\Mail\DesignerState;

defined( 'ABSPATH' ) || exit;

final class FeatureActions {
	private const RESULT_PREFIX = 'cb_core_mail_result_';

	public static function boot(): void {
		add_action( 'admin_post_cb_core_mail_features_save', [ __CLASS__, 'save' ] );
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage Mail capabilities.', 'core-blueprint' ),
				esc_html__( 'Forbidden', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}
		check_admin_referer( 'cb_core_mail_features_save' );

		$delivery = isset( $_POST['delivery_enabled'] );
		$designer = isset( $_POST['designer_enabled'] );
		$actor = 'user:' . get_current_user_id();

		try {
			DeliveryState::set_enabled( $delivery, $actor );
			DesignerState::set_enabled( $designer, $actor );
		} catch ( \Throwable $exception ) {
			self::set_result( 'error', __( 'Mail capability settings could not be saved.', 'core-blueprint' ) );
			self::redirect();
		}

		self::set_result( 'success', __( 'Mail capabilities saved.', 'core-blueprint' ) );
		self::redirect();
	}

	private static function set_result( string $type, string $message ): void {
		set_transient(
			self::RESULT_PREFIX . get_current_user_id(),
			[ 'type' => $type, 'message' => $message ],
			MINUTE_IN_SECONDS
		);
	}

	private static function redirect(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . Page::SLUG . '&tab=settings' ) );
		exit;
	}

	private function __construct() {}
}
