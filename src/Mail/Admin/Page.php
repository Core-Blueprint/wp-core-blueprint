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

namespace CB\Core\Mail\Admin;

use CB\Core\Admin\PageBase;
use CB\Core\Admin\TabNav;
use CB\Core\Design\Editor\Assets as DesignEditorAssets;
use CB\Core\Mail\ConflictDetector;
use CB\Core\Mail\DeliveryState;
use CB\Core\Mail\Designer\BindingRegistry;
use CB\Core\Mail\Designer\ComponentRegistry;
use CB\Core\Mail\Designer\Renderer as DesignerRenderer;
use CB\Core\Mail\Designer\TemplateRepository;
use CB\Core\Mail\Designer\TemplateRegistry;
use CB\Core\Mail\DesignerState;
use CB\Core\Mail\Runtime;
use CB\Core\Mail\Secrets;
use CB\Core\Mail\SenderIdentityRegistry;
use CB\Core\Mail\Settings;
use CB\Core\UI\Notice;
use CB\Core\UI\Status;

defined( 'ABSPATH' ) || exit;

final class Page extends PageBase {

	public const SLUG = 'core-blueprint-mail';

	public function slug(): string { return self::SLUG; }
	public function title(): string { return __( 'Mail', 'core-blueprint' ); }
	public function position(): ?int { return 29; }
	public function capability(): string { return 'manage_options'; }

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
			$templates = TemplateRegistry::all();
			$template_ids = array_keys( $templates );
			$template_id = isset( $_GET['template'] ) ? (string) wp_unslash( $_GET['template'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( $templates[ $template_id ] ) ) {
				$template_id = (string) ( $template_ids[0] ?? '' );
			}
			$current_template = '' !== $template_id ? TemplateRepository::get( $template_id ) : null;
			$bindings = BindingRegistry::all();
			$components = ComponentRegistry::all();
			$preview = is_array( $current_template ) ? DesignerRenderer::preview( $template_id ) : null;
			if ( $designer_enabled && is_array( $current_template ) ) {
				DesignEditorAssets::enqueue_designer_mode( __( 'Mail Designer', 'core-blueprint' ) );
			}
			include CB_CORE_DIR . 'templates/mail-designer.php';
		} elseif ( 'settings' === $tab ) {
			include CB_CORE_DIR . 'templates/mail-settings.php';
		} else {
			include CB_CORE_DIR . 'templates/mail-test.php';
		}
		$html = (string) ob_get_clean();

		echo TabNav::inject( $html, self::SLUG, $tab, $tabs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
