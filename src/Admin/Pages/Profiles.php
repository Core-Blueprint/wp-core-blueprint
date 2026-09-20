<?php
declare(strict_types=1);

namespace CB\Core\Admin\Pages;

use CB\Core\Admin\PageBase;
use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Profiles\Admin\Actions;
use CB\Core\Profiles\PreviewStore;
use CB\Core\Profiles\SectionRegistry;

defined( 'ABSPATH' ) || exit;

final class Profiles extends PageBase {
	public const SLUG = 'core-blueprint-profiles';

	public function slug(): string { return self::SLUG; }
	public function title(): string { return __( 'Profiles', 'core-blueprint' ); }
	public function menu_title(): string { return __( 'Profiles', 'core-blueprint' ); }
	public function capability(): string { return 'cb_manage_permissions'; }
	public function position(): ?int { return 85; }

	public function render(): void {
		$this->guard();
		$user = wp_get_current_user();
		if ( ! ( $user instanceof \WP_User ) || ! PrivilegedAccessGuard::is_trusted_operator( $user ) ) {
			wp_die( esc_html__( 'Only an approved Core Blueprint Operator may use Profiles.', 'core-blueprint' ), esc_html__( 'Forbidden', 'core-blueprint' ), [ 'response' => 403 ] );
		}
		$notice = get_transient( Actions::NOTICE_PREFIX . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( Actions::NOTICE_PREFIX . get_current_user_id() );
		}
		$token = isset( $_GET['preview'] ) ? (string) wp_unslash( $_GET['preview'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only opaque token; PreviewStore validates exact lowercase hex. // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only opaque preview token.
		$record = '' !== $token ? PreviewStore::get( get_current_user_id(), $token ) : null;
		$sections = SectionRegistry::all();
		?>
		<div class="wrap cb-core-wrap">
			<h1 class="cb-core-title"><?php esc_html_e( 'Profiles', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro"><?php esc_html_e( 'Export a governed Core Blueprint configuration and review every change before applying it to another site. Base-owned secret credentials, user assignments, audit evidence and site-bound runtime state are excluded from the Profile payload.', 'core-blueprint' ); ?></p>

			<?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
				<div class="notice <?php echo 'success' === ( $notice['type'] ?? '' ) ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( (string) $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_array( $record ) ) : ?>
				<?php $this->render_preview( $record, $token ); ?>
			<?php else : ?>
				<div class="cb-core-card">
					<h2><?php esc_html_e( 'Export profile', 'core-blueprint' ); ?></h2>
					<p><?php esc_html_e( 'Choose which portable configuration domains belong in the Profile. Base-owned credentials, user assignments, audit evidence and site-bound runtime state are not included in the exported payload.', 'core-blueprint' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( Actions::EXPORT_ACTION ); ?>">
						<?php wp_nonce_field( Actions::EXPORT_ACTION ); ?>
						<table class="form-table" role="presentation">
							<tr><th scope="row"><label for="cb-profile-name"><?php esc_html_e( 'Profile name', 'core-blueprint' ); ?></label></th><td><input class="regular-text" id="cb-profile-name" name="profile_name" type="text" maxlength="80" required></td></tr>
							<tr><th scope="row"><label for="cb-profile-description"><?php esc_html_e( 'Description', 'core-blueprint' ); ?></label></th><td><textarea class="large-text" id="cb-profile-description" name="profile_description" rows="3" maxlength="500"></textarea></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Configuration', 'core-blueprint' ); ?></th><td>
								<?php foreach ( $sections as $id => $section ) : ?>
									<label style="display:block;margin:0 0 12px"><input type="checkbox" name="sections[]" value="<?php echo esc_attr( $id ); ?>" checked> <strong><?php echo esc_html( $section->label() ); ?></strong><br><span class="description" style="margin-left:24px"><?php echo esc_html( $section->description() ); ?></span></label>
								<?php endforeach; ?>
							</td></tr>
						</table>
						<?php submit_button( __( 'Export Profile', 'core-blueprint' ) ); ?>
					</form>
				</div>

				<div class="cb-core-card" style="margin-top:20px">
					<h2><?php esc_html_e( 'Import profile', 'core-blueprint' ); ?></h2>
					<p><?php esc_html_e( 'Uploading a profile does not apply its configuration. Core Blueprint first validates the complete document and creates a diff against the current configuration.', 'core-blueprint' ); ?></p>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( Actions::PREVIEW_ACTION ); ?>">
						<?php wp_nonce_field( Actions::PREVIEW_ACTION ); ?>
						<input type="file" name="profile_file" accept="application/json,.json" required>
						<?php submit_button( __( 'Review Profile', 'core-blueprint' ), 'secondary' ); ?>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_preview( array $record, string $token ): void {
		$document = is_array( $record['document'] ?? null ) ? $record['document'] : [];
		$preview = is_array( $record['preview'] ?? null ) ? $record['preview'] : [];
		$name = (string) ( $document['profile']['name'] ?? __( 'Imported profile', 'core-blueprint' ) );
		$description = (string) ( $document['profile']['description'] ?? '' );
		?>
		<div class="cb-core-card">
			<h2><?php echo esc_html( $name ); ?></h2>
			<?php if ( '' !== $description ) : ?><p><?php echo esc_html( $description ); ?></p><?php endif; ?>
			<p><strong><?php esc_html_e( 'Changes found:', 'core-blueprint' ); ?></strong> <?php echo esc_html( (string) (int) ( $preview['total_changes'] ?? 0 ) ); ?></p>
		</div>

		<?php foreach ( (array) ( $preview['sections'] ?? [] ) as $id => $section ) : ?>
			<div class="cb-core-card" style="margin-top:16px">
				<h3><?php echo esc_html( (string) ( $section['label'] ?? $id ) ); ?> <span class="cb-core-state-badge"><?php echo esc_html( sprintf( _n( '%d change', '%d changes', (int) ( $section['count'] ?? 0 ), 'core-blueprint' ), (int) ( $section['count'] ?? 0 ) ) ); ?></span></h3>
				<?php if ( ! empty( $section['description'] ) ) : ?><p class="description"><?php echo esc_html( (string) $section['description'] ); ?></p><?php endif; ?>
				<?php foreach ( (array) ( $section['warnings'] ?? [] ) as $warning ) : ?><div class="notice notice-warning inline"><p><?php echo esc_html( (string) $warning ); ?></p></div><?php endforeach; ?>
				<?php if ( empty( $section['changes'] ) ) : ?>
					<p><?php esc_html_e( 'No changes.', 'core-blueprint' ); ?></p>
				<?php else : ?>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Setting', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Current', 'core-blueprint' ); ?></th><th><?php esc_html_e( 'Profile', 'core-blueprint' ); ?></th></tr></thead><tbody>
					<?php foreach ( (array) $section['changes'] as $change ) : ?>
						<tr><td><code><?php echo esc_html( (string) ( $change['path'] ?? '' ) ); ?></code></td><td><?php echo esc_html( $this->value_label( $change['before'] ?? null ) ); ?></td><td><?php echo esc_html( $this->value_label( $change['after'] ?? null ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<div class="cb-core-card" style="margin-top:20px">
			<h2><?php esc_html_e( 'Apply profile', 'core-blueprint' ); ?></h2>
			<p><?php esc_html_e( 'Core Blueprint will re-check the entire preview before changing anything. If configuration changed in the meantime, apply is refused. If a later section fails, Core Blueprint attempts to restore the sections already touched and reports any rollback that needs manual attention.', 'core-blueprint' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Actions::APPLY_ACTION ); ?>">
				<input type="hidden" name="preview_token" value="<?php echo esc_attr( $token ); ?>">
				<?php wp_nonce_field( Actions::APPLY_ACTION ); ?>
				<p><label for="cb-profile-password"><strong><?php esc_html_e( 'Confirm your password', 'core-blueprint' ); ?></strong></label><br><input id="cb-profile-password" name="password" type="password" autocomplete="current-password" required></p>
				<?php submit_button( __( 'Apply Profile', 'core-blueprint' ), 'primary' ); ?>
			</form>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>"><?php esc_html_e( 'Cancel and return to Profiles', 'core-blueprint' ); ?></a></p>
		</div>
		<?php
	}

	private function value_label( mixed $value ): string {
		if ( null === $value ) { return '∅'; }
		if ( is_bool( $value ) ) { return $value ? __( 'On', 'core-blueprint' ) : __( 'Off', 'core-blueprint' ); }
		if ( is_scalar( $value ) ) { return (string) $value; }
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}
}
