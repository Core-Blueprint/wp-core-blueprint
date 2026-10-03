<?php
declare(strict_types=1);
/**
 * Notes - Preferences tab fragment.
 *
 * Renders the Notes settings panel inside the central Preferences page.
 * Called from {@see \CoreBlueprint\Core\Admin\Pages\Preferences::render_notes_tab()}.
 *
 * No longer a standalone PageBase implementation - there is no separate
 * `cb-notes-preferences` admin page. Two static entry points:
 *
 *   - {@see maybe_handle_post()} - called by the Preferences tab handler
 *     before rendering, so a successful save can return a notice that
 *     gets injected above the form.
 *   - {@see render_body()} - pure presentation, called by the tab
 *     handler to emit the form markup.
 *
 * Settings live in the central CB Base settings array under the `notes`
 * subkey via {@see \CoreBlueprint\Core\Notes\Settings\SettingsRepository}, which
 * delegates writes to {@see \CoreBlueprint\Core\Settings::set_key()}.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Notes\Admin;

use CoreBlueprint\Core\Notes\Repository;
use CoreBlueprint\Core\Notes\Settings\SettingsRepository;
use CoreBlueprint\Core\Notes\State;

defined( 'ABSPATH' ) || exit;

final class PreferencesPage {

	/**
	 * Process the settings form POST if one is in flight. Returns a
	 * notice array on save, null otherwise. Called by the Preferences
	 * tab handler before rendering - so the tab can inject the notice
	 * above the form output.
	 *
	 * @return array{type:string,message:string}|null
	 */
	public static function maybe_handle_post(): ?array {
		if ( 'POST' !== strtoupper( sanitize_key( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) ) {
			return null;
		}

		if ( ! isset( $_POST['cb_notes_preferences_nonce'] ) ) {
			return null;
		}

		check_admin_referer( 'cb_notes_preferences', 'cb_notes_preferences_nonce' );

		if ( ! current_user_can( 'cb_manage_notes' ) ) {
			return [ 'type' => 'error', 'message' => __( 'You do not have permission to update Notes preferences.', 'core-blueprint' ) ];
		}

		SettingsRepository::update(
			[
				'default_type'          => isset( $_POST['default_type'] ) ? sanitize_text_field( wp_unslash( $_POST['default_type'] ) ) : 'General',
				'default_status'        => isset( $_POST['default_status'] ) ? sanitize_text_field( wp_unslash( $_POST['default_status'] ) ) : 'Backlog',
				'default_assigned_to'   => isset( $_POST['default_assigned_to'] ) ? (int) $_POST['default_assigned_to'] : 0,
				'details_initial_state' => isset( $_POST['details_initial_state'] ) ? sanitize_key( wp_unslash( $_POST['details_initial_state'] ) ) : 'remember',
				'default_layout'        => isset( $_POST['default_layout'] ) ? sanitize_key( wp_unslash( $_POST['default_layout'] ) ) : 'list',
			]
		);

		return [ 'type' => 'success', 'message' => __( 'Notes preferences saved.', 'core-blueprint' ) ];
	}

	/**
	 * Render the Notes preferences form. Pure presentation - call
	 * {@see maybe_handle_post()} first if you want save handling.
	 */
	public static function render_body(): void {
		$settings   = SettingsRepository::all();
		$users      = get_users( [ 'fields' => [ 'ID', 'display_name' ] ] );
		$is_enabled = State::is_enabled();
		?>
		<?php if ( ! $is_enabled ) : ?>
			<?php
			echo \CoreBlueprint\Core\UI\Notice::render( [
				'variant' => \CoreBlueprint\Core\UI\Notice::INFO,
				'title'   => __( 'Notes is disabled.', 'core-blueprint' ),
				'message' => __( 'Existing notes are preserved and these defaults remain editable. Enable Notes from the Dashboard when you want to use the module again.', 'core-blueprint' ),
				'class'   => 'cb-notes-disabled-notice',
			] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output
			?>
		<?php endif; ?>

		<section class="cb-core-preferences-section cb-notes-preferences-panel">
			<div class="cb-notes-preferences-panel__header">
				<h2><?php esc_html_e( 'Note modal defaults', 'core-blueprint' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Keep the note modal calm for new users, while allowing technical users to keep details open after they use them.', 'core-blueprint' ); ?></p>
			</div>

			<form method="post" class="cb-notes-preferences-form">
				<?php wp_nonce_field( 'cb_notes_preferences', 'cb_notes_preferences_nonce' ); ?>

				<table class="form-table cb-notes-preferences-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="cb-notes-default-type"><?php esc_html_e( 'Default type', 'core-blueprint' ); ?></label></th>
							<td><select id="cb-notes-default-type" name="default_type">
								<?php foreach ( Repository::allowed_types() as $type ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $settings['default_type'], $type ); ?>><?php echo esc_html( $type ); ?></option>
								<?php endforeach; ?>
							</select></td>
						</tr>
						<tr>
							<th scope="row"><label for="cb-notes-default-status"><?php esc_html_e( 'Default status', 'core-blueprint' ); ?></label></th>
							<td><select id="cb-notes-default-status" name="default_status">
								<?php foreach ( Repository::allowed_statuses() as $status ) : ?>
									<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $settings['default_status'], $status ); ?>><?php echo esc_html( $status ); ?></option>
								<?php endforeach; ?>
							</select></td>
						</tr>
						<tr>
							<th scope="row"><label for="cb-notes-default-assignee"><?php esc_html_e( 'Default assignment', 'core-blueprint' ); ?></label></th>
							<td><select id="cb-notes-default-assignee" name="default_assigned_to">
								<option value="0" <?php selected( (int) $settings['default_assigned_to'], 0 ); ?>><?php esc_html_e( 'Unassigned', 'core-blueprint' ); ?></option>
								<option value="-1" <?php selected( (int) $settings['default_assigned_to'], -1 ); ?>><?php esc_html_e( 'Current user', 'core-blueprint' ); ?></option>
								<?php foreach ( $users as $user ) : ?>
									<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( (int) $settings['default_assigned_to'], (int) $user->ID ); ?>><?php echo esc_html( $user->display_name ); ?></option>
								<?php endforeach; ?>
							</select></td>
						</tr>
						<tr>
							<th scope="row"><label for="cb-notes-default-layout"><?php esc_html_e( 'Default list layout', 'core-blueprint' ); ?></label></th>
							<td><select id="cb-notes-default-layout" name="default_layout">
								<option value="list" <?php selected( $settings['default_layout'], 'list' ); ?>><?php esc_html_e( 'List', 'core-blueprint' ); ?></option>
								<option value="grid-2" <?php selected( $settings['default_layout'], 'grid-2' ); ?>><?php esc_html_e( 'Grid, 2 columns', 'core-blueprint' ); ?></option>
								<option value="grid-3" <?php selected( $settings['default_layout'], 'grid-3' ); ?>><?php esc_html_e( 'Grid, 3 columns', 'core-blueprint' ); ?></option>
							</select></td>
						</tr>
						<tr>
							<th scope="row"><label for="cb-notes-details-state"><?php esc_html_e( 'Details section', 'core-blueprint' ); ?></label></th>
							<td><select id="cb-notes-details-state" name="details_initial_state">
								<option value="remember" <?php selected( $settings['details_initial_state'], 'remember' ); ?>><?php esc_html_e( 'Remember per user', 'core-blueprint' ); ?></option>
								<option value="closed" <?php selected( $settings['details_initial_state'], 'closed' ); ?>><?php esc_html_e( 'Closed by default', 'core-blueprint' ); ?></option>
								<option value="open" <?php selected( $settings['details_initial_state'], 'open' ); ?>><?php esc_html_e( 'Open by default', 'core-blueprint' ); ?></option>
							</select></td>
						</tr>
					</tbody>
				</table>

				<p class="description cb-notes-preferences-hint"><?php esc_html_e( 'Recommended: Remember per user for details and List as the default layout. Users can still switch their own layout from the Notes overview.', 'core-blueprint' ); ?></p>

				<p class="submit">
					<button type="submit" class="button cb-core-button cb-core-button--primary"><?php esc_html_e( 'Save preferences', 'core-blueprint' ); ?></button>
				</p>
			</form>
		</section>
		<?php
	}
}
