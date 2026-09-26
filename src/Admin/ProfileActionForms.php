<?php
declare(strict_types=1);
/**
 * Detached action forms for controls rendered inside WordPress' profile form.
 *
 * WordPress invokes show_user_profile/edit_user_profile before closing its own
 * #your-profile form. Base-owned independent admin-post actions therefore must
 * not emit nested <form> elements there. Visible form-associated controls use
 * their HTML form attribute to target these footer forms instead.
 *
 * @internal Base implementation detail; not a public extension contract.
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Admin;

defined( 'ABSPATH' ) || exit;

final class ProfileActionForms {

	/** @var array<string,array{action:string,nonce_action:string}> */
	private static array $forms = [];

	private static bool $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'admin_footer', [ self::class, 'render' ], 20 );
	}

	public static function register( string $form_id, string $action, ?string $nonce_action = null ): bool {
		$form_id = trim( $form_id );
		$action = trim( $action );
		$nonce_action = null === $nonce_action ? $action : trim( $nonce_action );

		if (
			1 !== preg_match( '/^[A-Za-z][A-Za-z0-9:._-]*$/', $form_id )
			|| '' === $action
			|| sanitize_key( $action ) !== $action
			|| '' === $nonce_action
		) {
			return false;
		}

		if ( isset( self::$forms[ $form_id ] ) ) {
			return self::$forms[ $form_id ]['action'] === $action
				&& self::$forms[ $form_id ]['nonce_action'] === $nonce_action;
		}

		self::$forms[ $form_id ] = [
			'action'       => $action,
			'nonce_action' => $nonce_action,
		];
		return true;
	}

	public static function render(): void {
		foreach ( self::$forms as $form_id => $definition ) {
			echo '<form id="' . esc_attr( $form_id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden aria-hidden="true">';
			echo '<input type="hidden" name="action" value="' . esc_attr( $definition['action'] ) . '">';
			wp_nonce_field( $definition['nonce_action'] );
			echo '</form>';
		}
	}

	/** Reset request-local state for tests. */
	public static function _reset_for_testing(): void {
		self::$forms = [];
	}
}
