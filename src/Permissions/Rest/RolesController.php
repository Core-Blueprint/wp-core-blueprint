<?php
declare(strict_types=1);
/**
 * REST controller for the User Roles editor.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Permissions\Rest;

use CoreBlueprint\Core\Permissions\RolePolicy;
use CoreBlueprint\Core\Permissions\RoleRepository;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class RolesController {

	public static function register(): void {
		register_rest_route( 'core-blueprint/v1', '/roles', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list' ],
			'permission_callback' => [ __CLASS__, 'can_manage' ],
		] );

		register_rest_route( 'core-blueprint/v1', '/roles/action', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'action' ],
			'permission_callback' => [ __CLASS__, 'can_manage' ],
		] );
	}

	public static function can_manage(): bool {
		return \CoreBlueprint\Core\Permissions\UserRolesState::is_enabled() && RolePolicy::can_manage_roles();
	}

	public static function list( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::valid_nonce( $request ) ) {
			return self::error( __( 'Invalid nonce.', 'core-blueprint' ), 403 );
		}
		return new WP_REST_Response( [
			'success' => true,
			'data'    => RoleRepository::snapshot(),
		] );
	}

	public static function action( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::valid_nonce( $request ) ) {
			return self::error( __( 'Invalid nonce.', 'core-blueprint' ), 403 );
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : [];
		$action  = sanitize_key( (string) ( $payload['action'] ?? '' ) );
		$selected = '';

		try {
			switch ( $action ) {
				case 'create':
					$selected = RoleRepository::create(
						(string) ( $payload['name'] ?? '' ),
						(string) ( $payload['slug'] ?? '' ),
						(string) ( $payload['source_role'] ?? '' )
					);
					$message = __( 'Role created.', 'core-blueprint' );
					break;

				case 'duplicate':
					$selected = RoleRepository::duplicate(
						(string) ( $payload['role'] ?? '' ),
						(string) ( $payload['name'] ?? '' ),
						(string) ( $payload['slug'] ?? '' )
					);
					$message = __( 'Role duplicated.', 'core-blueprint' );
					break;

				case 'rename':
					$selected = sanitize_key( (string) ( $payload['role'] ?? '' ) );
					RoleRepository::update_label( $selected, (string) ( $payload['name'] ?? '' ) );
					$message = __( 'Role name updated.', 'core-blueprint' );
					break;

				case 'save_capabilities':
					$selected = sanitize_key( (string) ( $payload['role'] ?? '' ) );
					$caps = isset( $payload['capabilities'] ) && is_array( $payload['capabilities'] )
						? $payload['capabilities']
						: [];
					RoleRepository::update_capabilities( $selected, $caps );
					$message = __( 'Capabilities updated.', 'core-blueprint' );
					break;

				case 'delete':
					$role = sanitize_key( (string) ( $payload['role'] ?? '' ) );
					RoleRepository::delete( $role );
					$message = __( 'Role deleted.', 'core-blueprint' );
					break;

				default:
					return self::error( __( 'Unknown role action.', 'core-blueprint' ), 400 );
			}
		} catch ( \InvalidArgumentException $e ) {
			return self::error( $e->getMessage(), 400 );
		} catch ( \RuntimeException $e ) {
			return self::error( $e->getMessage(), 403 );
		}

		return new WP_REST_Response( [
			'success'  => true,
			'message'  => $message,
			'selected' => $selected,
			'data'     => RoleRepository::snapshot(),
		] );
	}

	private static function valid_nonce( WP_REST_Request $request ): bool {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	private static function error( string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response( [ 'success' => false, 'message' => $message ], $status );
	}
}
