<?php
declare(strict_types=1);
/**
 * Base-owned extension plugin lifecycle AJAX endpoint.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Ajax\Handlers;

use CoreBlueprint\Core\Ajax\Request;
use CoreBlueprint\Core\ExtensionLifecycle as Lifecycle;

defined( 'ABSPATH' ) || exit;

final class ExtensionLifecycle {

	public static function init(): void {
		add_action( 'wp_ajax_cb_core_set_extension_active', [ __CLASS__, 'set_active' ] );
	}

	public static function set_active(): void {
		Request::nonce( 'cb_core_admin' );

		$extension_id = Request::sanitize_key( 'extension', Lifecycle::ids() );
		$capability   = Lifecycle::capability( $extension_id );
		if ( null === $capability ) {
			wp_send_json_error( [ 'message' => __( 'Unknown extension.', 'core-blueprint' ) ], 400 );
		}

		Request::cap( $capability );
		$active = Request::bool( 'active' );
		$result = Lifecycle::set_active( $extension_id, $active );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [
				'message' => sanitize_text_field( $result->get_error_message() ),
			], 409 );
		}

		wp_send_json_success( [
			'extension' => $extension_id,
			'active'    => Lifecycle::is_active( $extension_id ),
		] );
	}
}
