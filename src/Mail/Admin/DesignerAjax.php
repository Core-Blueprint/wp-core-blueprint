<?php
declare(strict_types=1);
/**
 * Live Mail Designer preview and context endpoints.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Mail\Admin;

use CB\Core\Mail\Designer\Renderer;
use CB\Core\Mail\Designer\TemplateRegistry;
use CB\Core\Mail\Designer\TemplateRepository;
use CB\Core\Mail\DesignerState;

defined( 'ABSPATH' ) || exit;

final class DesignerAjax {
	private const JSON_DEPTH = 128;

	public static function boot(): void {
		add_action( 'wp_ajax_cb_core_mail_designer_preview', [ __CLASS__, 'preview' ] );
		add_action( 'wp_ajax_cb_core_mail_designer_context', [ __CLASS__, 'context' ] );
	}

	public static function context(): void {
		self::guard();

		$template_id = self::template_id();
		$template    = TemplateRepository::get( $template_id );
		if ( null === $template ) {
			wp_send_json_error( [ 'message' => __( 'Unknown mail template.', 'core-blueprint' ) ], 404 );
		}

		$project = is_array( $template['project'] ?? null ) ? $template['project'] : null;
		$subject = isset( $template['subject'] ) ? (string) $template['subject'] : '';
		if ( null === $project || '' === $subject ) {
			wp_send_json_error( [ 'message' => __( 'The selected mail template is incomplete.', 'core-blueprint' ) ], 422 );
		}

		$preview = Renderer::preview( $template_id, $project, $subject );
		if ( null === $preview ) {
			wp_send_json_error( [ 'message' => __( 'The mail preview could not be rendered.', 'core-blueprint' ) ], 422 );
		}

		wp_send_json_success(
			[
				'template_id' => $template_id,
				'subject'     => $preview['subject'],
				'project'     => $project,
				'html'        => $preview['html'],
				'customized'  => ! empty( $template['customized'] ),
			],
			200,
			JSON_INVALID_UTF8_SUBSTITUTE
		);
	}

	public static function preview(): void {
		self::guard();

		$template_id = self::template_id();
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$json = isset( $_POST['project_json'] ) ? (string) wp_unslash( $_POST['project_json'] ) : '';
		try {
			$project = json_decode( $json, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			$project = null;
		}
		if ( ! is_array( $project ) || array_is_list( $project ) ) {
			wp_send_json_error( [ 'message' => __( 'The mail design is invalid.', 'core-blueprint' ) ], 422 );
		}

		$preview = Renderer::preview( $template_id, $project, $subject );
		if ( null === $preview ) {
			wp_send_json_error( [ 'message' => __( 'The mail preview could not be rendered.', 'core-blueprint' ) ], 422 );
		}

		wp_send_json_success(
			[
				'subject' => $preview['subject'],
				'html'    => $preview['html'],
			],
			200,
			JSON_INVALID_UTF8_SUBSTITUTE
		);
	}

	private static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to preview mail templates.', 'core-blueprint' ) ], 403 );
		}
		check_ajax_referer( 'cb_core_mail_designer_preview', 'nonce' );

		if ( ! DesignerState::is_enabled() ) {
			wp_send_json_error( [ 'message' => __( 'Mail Designer is disabled.', 'core-blueprint' ) ], 409 );
		}
	}

	private static function template_id(): string {
		$template_id = isset( $_POST['template_id'] ) ? trim( (string) wp_unslash( $_POST['template_id'] ) ) : '';
		// TemplateRegistry owns the canonical identifier grammar, which deliberately
		// permits provider-qualified dot-separated IDs. Do not run sanitize_key()
		// here because it strips dots and would corrupt valid registered IDs.
		if ( '' === $template_id || null === TemplateRegistry::get( $template_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown mail template.', 'core-blueprint' ) ], 404 );
		}
		return $template_id;
	}

	private function __construct() {}
}
