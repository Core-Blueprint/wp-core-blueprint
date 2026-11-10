<?php
declare(strict_types=1);
/**
 * Core Blueprint Mail admin page.
 *
 * One surface owns independent Mail Designer and Mail Delivery capabilities.
 * Installed extensions contribute templates to the same Base-owned designer.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail\Admin;

use CoreBlueprint\Core\Admin\PageBase;
use CoreBlueprint\Core\Admin\TabNav;
use CoreBlueprint\Core\Design\Editor\Assets as DesignEditorAssets;
use CoreBlueprint\Core\Mail\ConflictDetector;
use CoreBlueprint\Core\Mail\DeliveryState;
use CoreBlueprint\Core\Brand\CoreBlueprintMark;
use CoreBlueprint\Core\Mail\Designer\BindingRegistry;
use CoreBlueprint\Core\Mail\Designer\ComponentRegistry;
use CoreBlueprint\Core\Mail\Designer\Renderer as DesignerRenderer;
use CoreBlueprint\Core\Mail\Designer\DocumentUnavailable;
use CoreBlueprint\Core\Mail\Designer\TemplateRepository;
use CoreBlueprint\Core\Mail\Designer\TemplateRegistry;
use CoreBlueprint\Core\Mail\DesignerState;
use CoreBlueprint\Core\Mail\Runtime;
use CoreBlueprint\Core\Mail\Secrets;
use CoreBlueprint\Core\Mail\SenderIdentityRegistry;
use CoreBlueprint\Core\Mail\Settings;
use CoreBlueprint\Core\UI\Notice;
use CoreBlueprint\Core\UI\Status;

defined( 'ABSPATH' ) || exit;

final class Page extends PageBase {

	public const SLUG = 'core-blueprint-mail';

	public function slug(): string { return self::SLUG; }
	public function title(): string { return __( 'Mail', 'core-blueprint' ); }
	public function position(): ?int { return 29; }
	public function capability(): string { return 'manage_options'; }

	/**
	 * Build native, escaped optgroups for the existing landing and Header Picker.
	 * Only active validated registry entries are present. No parallel selector.
	 *
	 * @param array<string,array<string,mixed>> $templates
	 */
	private static function template_options( array $templates, string $selected ): string {
		$groups = [];
		foreach ( $templates as $id => $definition ) {
			$provider = (string) $definition['provider'];
			if ( ! isset( $groups[ $provider ] ) ) {
				$groups[ $provider ] = [
					'label' => 'core' === $provider ? __( 'WordPress', 'core-blueprint' )
						: ucwords( str_replace( [ '-', '_' ], ' ', $provider ) ),
					'templates' => [],
				];
			}
			$groups[ $provider ]['templates'][ $id ] = $definition;
		}
		uksort( $groups, static fn ( string $a, string $b ): int =>
			'core' === $a ? -1 : ( 'core' === $b ? 1 : strcmp( $a, $b ) ) );
		$html = '';
		foreach ( $groups as $group ) {
			$html .= '<optgroup label="' . esc_attr( (string) $group['label'] ) . '">';
			foreach ( $group['templates'] as $id => $definition ) {
				$html .= '<option value="' . esc_attr( (string) $id ) . '"'
					. selected( $id, $selected, false ) . '>'
					. esc_html( (string) $definition['label'] ) . '</option>';
			}
			$html .= '</optgroup>';
		}
		return $html;
	}

	public function render(): void {
		$this->guard();

		$tabs = [
			'overview'  => __( 'Overview', 'core-blueprint' ),
			'templates' => __( 'Templates', 'core-blueprint' ),
			'settings'  => __( 'Delivery', 'core-blueprint' ),
			'test'      => __( 'Test Email', 'core-blueprint' ),
			'logs'      => __( 'Logs', 'core-blueprint' ),
		];
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $tabs[ $requested ] ) ? $requested : 'overview';

		if ( 'logs' === $tab ) {
			$html = LogsTab::html( self::SLUG, 'logs' );
			echo TabNav::inject( $html, self::SLUG, $tab, $tabs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$settings                  = Settings::all();
		$sender_identities         = SenderIdentityRegistry::snapshot();
		$sender_identity_overrides = Settings::sender_identity_overrides();
		$delivery_enabled          = DeliveryState::is_enabled();
		$designer_enabled          = DesignerState::is_enabled();
		$conflicts                 = ConflictDetector::active();
		$runtime_active            = Runtime::is_active();
		$has_brevo_secret          = '' !== Secrets::decrypt( (string) $settings['brevo_api_key'] );
		$has_smtp_password         = '' !== Secrets::decrypt( (string) $settings['smtp_password'] );
		$provider_label            = Settings::provider_label();
		$retention_options         = Settings::RETENTION_DAYS;
		$activation_error          = Settings::activation_error( $settings );

		$delivery_status_html = Status::render( $delivery_enabled ? 'active' : 'idle', $delivery_enabled ? __( 'Enabled', 'core-blueprint' ) : __( 'Disabled', 'core-blueprint' ) );
		$designer_status_html = Status::render( $designer_enabled ? 'active' : 'idle', $designer_enabled ? __( 'Enabled', 'core-blueprint' ) : __( 'Disabled', 'core-blueprint' ) );
		$module_status_html = $delivery_status_html;
		$runtime_status_html = Status::render( $runtime_active ? 'active' : 'idle', $runtime_active ? __( 'Active', 'core-blueprint' ) : __( 'Inactive', 'core-blueprint' ) );
		$settings_runtime_status_html = Status::render(
			$runtime_active ? 'active' : ( $delivery_enabled && ( ! empty( $conflicts ) || '' !== $activation_error ) ? 'warning' : 'idle' ),
			$runtime_active
				? __( 'Active', 'core-blueprint' )
				: ( $delivery_enabled && '' !== $activation_error
					? __( 'Configuration required', 'core-blueprint' )
					: ( $delivery_enabled && ! empty( $conflicts ) ? __( 'Blocked', 'core-blueprint' ) : __( 'Disabled', 'core-blueprint' ) ) )
		);
		$conflict_status_html = ! empty( $conflicts )
			? Status::render( 'warning', implode( ', ', $conflicts ) )
			: '';

		$result = Actions::pull_result();
		$result_notice_html = '';
		$result_toast_message = '';
		if ( is_array( $result ) && ! empty( $result['message'] ) ) {
			if ( 'test' === $tab && 'success' === ( $result['type'] ?? '' ) ) {
				$result_toast_message = (string) $result['message'];
			} else {
				$result_notice_html = Notice::render( [
					'variant' => 'error' === ( $result['type'] ?? '' ) ? Notice::ERROR : Notice::SUCCESS,
					'message' => (string) $result['message'],
				] );
			}
		}

		$conflict_notice = '';
		if ( $delivery_enabled && ! empty( $conflicts ) ) {
			$conflict_notice = Notice::render( [
				'variant' => Notice::WARNING,
				'title'   => __( 'Transport conflict detected', 'core-blueprint' ),
				'message' => sprintf(
					/* translators: %s: active conflicting mail transport plugin names */
					__( 'Active mail transport: %s. Core Blueprint Delivery stays inactive until the conflict is removed; Mail Designer is unaffected.', 'core-blueprint' ),
					implode( ', ', $conflicts )
				),
			] );
		}

		ob_start();
		if ( 'overview' === $tab ) {
			include CB_CORE_DIR . 'templates/mail-features.php';
		} elseif ( 'templates' === $tab ) {
			// Platform v1 owns the ONE Mail editor and Save authority. The
			// provider catalog is used only for trusted resource identities:
			// never read an old stored project or PHP-render an older canvas.
			$templates = TemplateRegistry::all();
			$template_ids = array_keys( $templates );
			$template_id = isset( $_GET['template'] ) ? sanitize_text_field( wp_unslash( $_GET['template'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector.
			if ( ! isset( $templates[ $template_id ] ) ) {
				$template_id = (string) ( $template_ids[0] ?? '' );
			}
			$options_html = self::template_options( $templates, $template_id );
			if ( ! $designer_enabled || '' === $template_id ) {
				echo '<div class="wrap cb-core-wrap"><section class="cb-core-panel"><h2>' . esc_html__( 'Mail Designer unavailable', 'core-blueprint' ) . '</h2><p>' . esc_html__( 'Enable Mail Designer and register at least one template before editing.', 'core-blueprint' ) . '</p></section></div>';
			} else {
				$editor_mode = DesignEditorAssets::platform_fullscreen_requested();
				$landing_url = add_query_arg(
					[ 'page' => self::SLUG, 'tab' => 'templates', 'template' => $template_id ],
					admin_url( 'admin.php' )
				);
				?>
				<?php if ( ! $editor_mode ) : ?>
					<div class="wrap cb-core-wrap cb-core-mail-designer-wrap">
						<div class="cb-core-mail-designer">
							<section class="cb-core-panel cb-core-mail-designer__context" aria-label="<?php esc_attr_e( 'Mail template context', 'core-blueprint' ); ?>">
								<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="cb-core-mail-designer__launch-form">
									<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
									<input type="hidden" name="tab" value="templates">
									<label class="cb-core-field__label" for="cb-mail-platform-template"><?php esc_html_e( 'Template', 'core-blueprint' ); ?></label>
									<select id="cb-mail-platform-template" name="template" required>
										<?php echo $options_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- options escaped during composition. ?>
									</select>
									<div class="cb-core-design-launch-wrap">
										<button type="submit" name="cb_designer" value="1" class="button button-primary cb-core-button cb-core-button--primary cb-core-design-launch" aria-label="<?php esc_attr_e( 'Open Designer Mode', 'core-blueprint' ); ?>">
											<img class="cb-core-design-launch__mark" src="<?php echo esc_attr( CoreBlueprintMark::data_uri() ); ?>" alt="" aria-hidden="true">
											<span class="cb-core-design-launch__label"><?php esc_html_e( 'Design with Core Blueprint', 'core-blueprint' ); ?></span>
										</button>
									</div>
								</form>
							</section>
						</div>
						<section class="cb-core-panel" data-cb-mail-saved-manager aria-label="<?php esc_attr_e( 'Saved Mail templates', 'core-blueprint' ); ?>">
							<h2><?php esc_html_e( 'Saved Mail templates', 'core-blueprint' ); ?></h2>
							<p><?php esc_html_e( 'Manage reusable personal and site templates here. Edit live WordPress and extension notification designs in the Mail Designer.', 'core-blueprint' ); ?></p>
							<div data-cb-mail-saved-list role="status"><?php esc_html_e( 'Template operation could not be verified. Reload before retrying.', 'core-blueprint' ); ?></div>
						</section>
					</div>
				<?php else : ?>
					<div class="cb-core-platform-screen" data-cb-platform-screen aria-busy="true">
						<header class="cb-core-platform-screen__header" hidden>
							<strong><?php esc_html_e( 'Mail Designer', 'core-blueprint' ); ?></strong>
							<label class="screen-reader-text" for="cb-mail-platform-template"><?php esc_html_e( 'Template', 'core-blueprint' ); ?></label>
							<select id="cb-mail-platform-template" class="cb-core-platform-screen__subtitle" data-cb-mail-template-select aria-label="<?php esc_attr_e( 'Template', 'core-blueprint' ); ?>">
								<?php echo $options_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- options escaped during composition. ?>
							</select>
							<a class="button cb-core-button cb-core-platform-screen__close" data-cb-platform-header-close href="<?php echo esc_url( $landing_url ); ?>">
								<?php esc_html_e( 'Close Designer', 'core-blueprint' ); ?>
							</a>
						</header>
						<div class="cb-core-platform-screen__body">
							<div data-cb-mail-designer data-cb-mail-template="<?php echo esc_attr( $template_id ); ?>" data-cb-mail-scope="<?php echo esc_attr( (string) \CoreBlueprint\Core\TemplateLibrary\DesignerScope::issue( \CoreBlueprint\Core\Mail\Designer\NativeTemplateSource::SOURCE_ID, \CoreBlueprint\Core\Design\Document\Repository::MAIL, $template_id ) ); ?>">
								<div data-cb-platform-base-context>
									<p role="status"><?php esc_html_e( 'Verifying the Designer and loading the selected template…', 'core-blueprint' ); ?></p>
								</div>
							</div>
						</div>
					</div>
				<?php endif; ?>
				<?php

			}
		} elseif ( 'settings' === $tab ) {
			include CB_CORE_DIR . 'templates/mail-settings.php';
		} else {
			include CB_CORE_DIR . 'templates/mail-test.php';
		}
		$html = (string) ob_get_clean();

		echo TabNav::inject( $html, self::SLUG, $tab, $tabs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
