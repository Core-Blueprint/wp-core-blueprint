<?php
declare(strict_types=1);
/**
 * UserRolesFields
 *
 * Adds Core Blueprint's additive role controls to WordPress user profiles.
 * WordPress keeps ownership of the native Role dropdown (the base role); this
 * integration adds zero or more additional roles without replacing that core
 * UI or storing duplicate assignment data.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Permissions\Admin;

use CB\Core\Permissions\Roles;
use CB\Core\Permissions\UserRoleAssignments;

defined( 'ABSPATH' ) || exit;

final class UserRolesFields {

	private const NONCE_ACTION = 'cb_core_user_roles_';
	private const NONCE_FIELD  = 'cb_core_user_roles_nonce';
	private const FORM_MARKER  = 'cb_core_additional_roles_present';
	private const FIELD_NAME   = 'cb_core_additional_roles';

	/**
	 * Per-request validated synchronization plans, keyed by user ID.
	 *
	 * @var array<int,array{base:string,previous_base:string,additional:string[],manual_base_change:bool}>
	 */
	private static array $pending = [];

	public static function init(): void {
		add_action( 'edit_user_profile', [ __CLASS__, 'render' ] );
		add_action( 'show_user_profile', [ __CLASS__, 'render' ] );
		add_action( 'user_profile_update_errors', [ __CLASS__, 'validate_update' ], 20, 3 );
		add_action( 'profile_update', [ __CLASS__, 'apply_update' ], 20, 3 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_filter( 'editable_roles', [ __CLASS__, 'filter_editable_roles' ] );
	}

	/**
	 * Keep the protected CB Operator role out of WordPress' native base-role
	 * selector for every actor. CB Operator is an additive governance role,
	 * never a replacement for the user's normal WordPress base role.
	 *
	 * Assignment remains available through Core Blueprint's Additional roles
	 * controls and the canonical `cb operator add` / `cb operator recover` CLI
	 * transactions. validate_update() independently rejects crafted base-role
	 * requests so this invariant is not merely presentational.
	 *
	 * @param array<string,array<string,mixed>> $roles
	 * @return array<string,array<string,mixed>>
	 */
	public static function filter_editable_roles( array $roles ): array {
		unset( $roles[ Roles::OPERATOR_ROLE ] );
		return $roles;
	}

	/**
	 * Render additive-role controls below the standard profile sections.
	 */
	public static function render( \WP_User $profile_user ): void {
		$user_id = (int) $profile_user->ID;
		if ( ! UserRoleAssignments::can_manage_user( $user_id ) ) {
			return;
		}

		$base       = UserRoleAssignments::base_role( $profile_user );
		$additional = UserRoleAssignments::additional_roles( $profile_user );
		$all_roles    = wp_roles()->roles;
		$default_role = sanitize_key( (string) get_option( 'default_role', 'subscriber' ) );

		$base_label = '';
		if ( '' !== $base && isset( $all_roles[ $base ]['name'] ) ) {
			$base_label = translate_user_role( (string) $all_roles[ $base ]['name'] );
		}

		wp_nonce_field( self::NONCE_ACTION . $user_id, self::NONCE_FIELD );
		?>
		<input type="hidden" name="<?php echo esc_attr( self::FORM_MARKER ); ?>" value="1" />
		<h2><?php echo esc_html__( 'Core Blueprint User Roles', 'core-blueprint' ); ?></h2>
		<table class="form-table cb-core-user-roles-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Base role', 'core-blueprint' ); ?></th>
				<td>
					<?php if ( '' !== $base ) : ?>
						<strong><?php echo esc_html( $base_label ?: $base ); ?></strong>
						<code><?php echo esc_html( $base ); ?></code>
					<?php else : ?>
						<strong><?php echo esc_html__( 'No role for this site', 'core-blueprint' ); ?></strong>
					<?php endif; ?>
					<p class="description">
						<?php
						if ( get_current_user_id() === $user_id ) {
							echo esc_html__( 'The base role is managed by WordPress and cannot be changed from your own profile screen.', 'core-blueprint' );
						} else {
							echo esc_html__( 'The WordPress Role field above is the base role. Changing it keeps the additional roles selected below.', 'core-blueprint' );
						}
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Additional roles', 'core-blueprint' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php echo esc_html__( 'Additional roles', 'core-blueprint' ); ?></legend>
						<p class="description cb-core-additional-roles-description">
							<?php echo esc_html__( 'Additional roles are additive. The user receives the combined capabilities of the base role and every selected additional role.', 'core-blueprint' ); ?>
						</p>
						<div class="cb-core-additional-roles">
							<?php
							$rendered = 0;
							foreach ( $all_roles as $slug => $definition ) {
								$slug = sanitize_key( (string) $slug );
								if ( '' === $slug || $slug === $base ) {
									continue;
								}

								$is_assigned = in_array( $slug, $additional, true );
								$can_toggle  = $is_assigned
									? UserRoleAssignments::can_remove_role( $slug, $user_id )
									: UserRoleAssignments::can_assign_role( $slug, $user_id );

								// Existing protected assignments stay visible. Unassigned roles the
								// actor cannot grant are omitted instead of presenting dead controls.
								if ( ! $is_assigned && ! $can_toggle ) {
									continue;
								}

								++$rendered;
								$is_system = in_array( $slug, [ 'administrator', Roles::OPERATOR_ROLE ], true );
								$is_default = $slug === $default_role;
								$name       = isset( $definition['name'] ) ? translate_user_role( (string) $definition['name'] ) : $slug;
								?>
								<div class="cb-core-additional-role<?php echo $can_toggle ? '' : ' is-locked'; ?>">
									<label class="cb-core-additional-role-control">
										<input
											type="checkbox"
											name="<?php echo esc_attr( self::FIELD_NAME ); ?>[]"
											value="<?php echo esc_attr( $slug ); ?>"
											<?php checked( $is_assigned ); ?>
											<?php disabled( ! $can_toggle ); ?>
										/>
										<strong><?php echo esc_html( $name ); ?></strong>
										<?php if ( $is_system ) : ?>
											<span class="cb-core-additional-role-status"><?php echo esc_html__( 'System role', 'core-blueprint' ); ?></span>
										<?php elseif ( $is_default ) : ?>
											<span class="cb-core-additional-role-status"><?php echo esc_html__( 'Default role', 'core-blueprint' ); ?></span>
										<?php endif; ?>
										<?php if ( ! $can_toggle ) : ?>
											<span class="cb-core-additional-role-status"><?php echo esc_html__( 'Protected', 'core-blueprint' ); ?></span>
										<?php endif; ?>
									</label>
									<div class="cb-core-additional-role-meta">
										<code><?php echo esc_html( $slug ); ?></code>
									</div>
								</div>
								<?php
							}
							?>
						</div>
						<?php if ( 0 === $rendered ) : ?>
							<p class="description"><?php echo esc_html__( 'No additional roles are available for this account.', 'core-blueprint' ); ?></p>
						<?php endif; ?>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Validate and prepare the role synchronization before WordPress mutates
	 * the user's base role.
	 *
	 * The $user object is passed by reference by WordPress. When the selected
	 * base role has not changed we unset its role property, preventing
	 * WP_User::set_role() from pointlessly deleting all additional roles on
	 * every ordinary profile save.
	 */
	public static function validate_update( \WP_Error $errors, bool $update, \stdClass $user ): void {
		if ( ! $update || empty( $user->ID ) ) {
			return;
		}

		$user_id = (int) $user->ID;
		$old     = get_userdata( $user_id );
		if ( ! ( $old instanceof \WP_User ) ) {
			return;
		}

		$old_base       = UserRoleAssignments::base_role( $old );
		$old_additional = UserRoleAssignments::additional_roles( $old );
		$requested_base = property_exists( $user, 'role' ) ? sanitize_key( (string) $user->role ) : $old_base;
		$has_ui_payload = isset( $_POST[ self::FORM_MARKER ] ) && '1' === sanitize_text_field( wp_unslash( $_POST[ self::FORM_MARKER ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- marker only; nonce is verified before accepting role changes.

		// CB Operator is never a WordPress base role. WordPress' native
		// set_role() semantics replace every existing role, so accepting
		// cb_operator here could silently remove Administrator or another normal
		// base role. Operator assignment is additive and belongs exclusively to
		// Core Blueprint's Additional roles / trusted Operator flows.
		if ( $requested_base !== $old_base && Roles::OPERATOR_ROLE === $requested_base ) {
			$errors->add(
				'cb_core_user_roles_operator_base_role',
				__( 'CB Operator is an additional governance role and cannot be used as the WordPress base role. Assign it through Core Blueprint Additional roles instead.', 'core-blueprint' )
			);
			if ( property_exists( $user, 'role' ) ) {
				unset( $user->role );
			}
			return;
		}

		// A same-base update should never invoke WP_User::set_role(), because
		// set_role() intentionally replaces every role. Removing the property
		// keeps WordPress from destroying/recreating additional assignments.
		if ( property_exists( $user, 'role' ) && $requested_base === $old_base ) {
			unset( $user->role );
		}

		if ( ! $has_ui_payload ) {
			// Another admin/plugin is using the normal WordPress profile form but
			// cannot manage Core Blueprint roles. If the base role changes, keep
			// all pre-existing additional roles. If it does not change, unsetting
			// $user->role above already preserves them in place.
			if ( $requested_base !== $old_base ) {
				$manual_base_change = '' !== $requested_base && ! empty( $old_additional );
				if ( $manual_base_change && property_exists( $user, 'role' ) ) {
					unset( $user->role );
				}

				self::$pending[ $user_id ] = [
					'base'               => $requested_base,
					'previous_base'      => $old_base,
					'additional'         => array_values( array_filter(
						$old_additional,
						static fn( string $role ): bool => $role !== $requested_base
					) ),
					'manual_base_change' => $manual_base_change,
				];
			}
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $user_id ) ) {
			$errors->add( 'cb_core_user_roles_nonce', __( 'Core Blueprint could not verify the additional-role change. Reload the page and try again.', 'core-blueprint' ) );
			return;
		}

		if ( ! UserRoleAssignments::can_manage_user( $user_id ) ) {
			$errors->add( 'cb_core_user_roles_forbidden', __( 'You are not allowed to manage additional roles for this user.', 'core-blueprint' ) );
			return;
		}

		$raw = isset( $_POST[ self::FIELD_NAME ] ) ? (array) wp_unslash( $_POST[ self::FIELD_NAME ] ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$desired = array_values( array_unique( array_filter( array_map(
			static fn( $value ): string => sanitize_key( is_scalar( $value ) ? (string) $value : '' ),
			$raw
		) ) ) );
		$desired = array_values( array_filter(
			$desired,
			static fn( string $role ): bool => $role !== $requested_base
		) );

		// Preserve existing assignments that are protected from this actor.
		foreach ( $old_additional as $role_slug ) {
			if ( ! UserRoleAssignments::can_remove_role( $role_slug, $user_id ) ) {
				$desired[] = $role_slug;
			}
		}
		$desired = array_values( array_unique( $desired ) );

		// A requested role that was not already assigned is a new privilege
		// grant and must pass the actor-capability boundary.
		foreach ( $desired as $role_slug ) {
			if ( null === get_role( $role_slug ) ) {
				$errors->add(
					'cb_core_user_roles_missing_role',
					sprintf(
						/* translators: %s: role slug */
						__( 'The %s role no longer exists.', 'core-blueprint' ),
						$role_slug
					)
				);
				continue;
			}

			if ( ! in_array( $role_slug, $old_additional, true ) ) {
				try {
					UserRoleAssignments::assert_can_assign_role( $role_slug, $user_id );
				} catch ( \RuntimeException $exception ) {
					$errors->add( 'cb_core_user_roles_assignment', $exception->getMessage() );
				}
			}
		}

		if ( $errors->has_errors() ) {
			return;
		}

		$manual_base_change = $requested_base !== $old_base && '' !== $requested_base && ! empty( $old_additional );
		if ( $manual_base_change && property_exists( $user, 'role' ) ) {
			unset( $user->role );
		}

		self::$pending[ $user_id ] = [
			'base'               => $requested_base,
			'previous_base'      => $old_base,
			'additional'         => $desired,
			'manual_base_change' => $manual_base_change,
		];
	}

	/**
	 * Apply the validated additive assignments after WordPress has saved the
	 * base role. profile_update runs after WP_User::set_role(), so additions
	 * removed by a real base-role change can safely be restored here.
	 *
	 * @param array<string,mixed> $userdata Raw data passed to wp_insert_user().
	 */
	public static function apply_update( int $user_id, \WP_User $old_user_data, array $userdata ): void {
		if ( ! isset( self::$pending[ $user_id ] ) ) {
			// Lazily establish base metadata for existing multi-role users even
			// when this particular profile update did not touch role assignments.
			$current = get_userdata( $user_id );
			if ( $current instanceof \WP_User && '' === (string) get_user_meta( $user_id, UserRoleAssignments::BASE_ROLE_META, true ) ) {
				UserRoleAssignments::set_base_role( $user_id, UserRoleAssignments::base_role( $current ) );
			}
			return;
		}

		$plan = self::$pending[ $user_id ];
		unset( self::$pending[ $user_id ] );

		$base = sanitize_key( (string) $plan['base'] );
		if ( '' === $base ) {
			// Respect WordPress' explicit "No role for this site" state. Without
			// a base role Core Blueprint does not attach additional roles.
			UserRoleAssignments::set_base_role( $user_id, '' );
			return;
		}

		if ( ! empty( $plan['manual_base_change'] ) ) {
			UserRoleAssignments::change_base_role(
				$user_id,
				(string) $plan['previous_base'],
				$base,
				(array) $plan['additional']
			);
			return;
		}

		UserRoleAssignments::sync_additional_roles(
			$user_id,
			$base,
			(array) $plan['additional']
		);
	}

	/**
	 * Minimal styling on native WordPress profile screens.
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'user-edit.php', 'profile.php' ], true ) ) {
			return;
		}

		wp_enqueue_style(
			'cb-core-user-role-assignments',
			CB_CORE_URL . 'assets/css/pages/user-role-assignments.css',
			[],
			CB_CORE_VERSION
		);
	}
}
