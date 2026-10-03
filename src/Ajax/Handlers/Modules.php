<?php
declare(strict_types=1);
/**
 * Optional module master-switch AJAX endpoint.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Ajax\Handlers;

use CoreBlueprint\Core\Ajax\Request;
use CoreBlueprint\Core\Modules\ActivationRegistry;

defined( 'ABSPATH' ) || exit;

final class Modules {

	public static function init(): void {
		add_action( 'wp_ajax_cb_core_set_module_enabled', [ __CLASS__, 'set_enabled' ] );
	}

	public static function set_enabled(): void {
		Request::nonce( 'cb_core_admin' );

		$slug       = Request::sanitize_key( 'module', ActivationRegistry::slugs() );
		$definition = ActivationRegistry::definition( $slug );
		if ( null === $definition ) {
			wp_send_json_error( [ 'message' => __( 'Unknown module.', 'core-blueprint' ) ], 400 );
		}

		Request::cap( $definition['capability'] );
		$enabled = Request::bool( 'enabled' );
		$state   = $definition['state'];
		$actor   = 'user:' . get_current_user_id();

		try {
			$state::set_enabled( $enabled, $actor );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [
				'message' => sanitize_text_field( $e->getMessage() ),
			], 409 );
		}

		wp_send_json_success( [
			'module'  => $slug,
			'enabled' => (bool) $state::is_enabled(),
		] );
	}
}
