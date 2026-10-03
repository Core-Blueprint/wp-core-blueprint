<?php
declare(strict_types=1);
/**
 * Router - AJAX endpoints for scoped user preferences.
 *
 * Routes two actions:
 *   - cb_core_set_theme   → {@see Themes}
 *   - cb_core_set_locale  → {@see Locale}
 *
 * Both handlers are thin wrappers over the same internal dispatch:
 * validate the nonce + capability, hand off to the target class's
 * trait-provided set_user() or set_site_default(), return the new
 * resolved value so the browser can update its UI without a page reload.
 *
 * Collapsing scoped preference handlers into {@see dispatch_scoped_preference()}
 * avoids duplicated nonce/capability/dispatch boilerplate and means future
 * preferences that adopt the
 * {@see ScopedPreference} trait can register here with one more
 * add_action line each.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Ajax;

use CoreBlueprint\Core\Locale;
use CoreBlueprint\Core\Themes;

defined( 'ABSPATH' ) || exit;

final class Router {

	/** Valid 'scope' parameter values. */
	private const SCOPES = [ 'user', 'site' ];

	public static function init(): void {
		add_action( 'wp_ajax_cb_core_set_theme',  [ __CLASS__, 'set_theme'  ] );
		add_action( 'wp_ajax_cb_core_set_locale', [ __CLASS__, 'set_locale' ] );
	}

	// ─── Public handlers ──────────────────────────────────────────────────────

	public static function set_theme(): void {
		self::dispatch_scoped_preference( 'cb_core_theme', Themes::class );
	}

	public static function set_locale(): void {
		self::dispatch_scoped_preference( 'cb_core_locale', Locale::class );
	}

	// ─── Shared dispatch ──────────────────────────────────────────────────────

	/**
	 * Handle a scoped-preference AJAX request against a class that uses the
	 * {@see \CoreBlueprint\Core\Preferences\ScopedPreference} trait.
	 *
	 * Request shape (POST):
	 *   - nonce : string - must verify against $nonce_action
	 *   - scope : 'user' | 'site'
	 *   - value : string - the new value (empty clears user override)
	 *
	 * Response on success:
	 *   - scope   : echo of the request scope
	 *   - value   : echo of the requested value
	 *   - current : the resolved value after the write (::current())
	 *
	 * @param string            $nonce_action Nonce action to verify.
	 * @param class-string      $target       Class with set_user() / set_site_default() / current().
	 */
	private static function dispatch_scoped_preference( string $nonce_action, string $target ): void {
		Request::nonce( $nonce_action );

		$scope = Request::sanitize_key( 'scope', self::SCOPES );
		$value = Request::text( 'value' );

		if ( 'user' === $scope ) {
			Request::cap( 'read' );
			$uid = get_current_user_id();
			if ( ! $target::set_user( $uid, $value ) ) {
				wp_send_json_error( [ 'message' => __( 'Invalid value.', 'core-blueprint' ) ], 400 );
			}
		} else {
			// 'site' - validated against SCOPES above.
			Request::cap( 'manage_options' );
			if ( ! $target::set_site_default( $value ) ) {
				wp_send_json_error( [ 'message' => __( 'Invalid value.', 'core-blueprint' ) ], 400 );
			}
		}

		wp_send_json_success( [
			'scope'   => $scope,
			'value'   => $value,
			'current' => $target::current(),
		] );
	}
}
