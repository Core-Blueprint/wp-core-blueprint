<?php
declare(strict_types=1);
/**
 * Admin handlers for Mail Designer template persistence.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Mail\Admin;

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Mail\Designer\TemplateRepository;
use CoreBlueprint\Core\Mail\Designer\TemplateRegistry;
use CoreBlueprint\Core\Mail\DesignerState;

defined( 'ABSPATH' ) || exit;

final class TemplateActions {
	private const RESULT_PREFIX = 'cb_core_mail_result_';
	private const JSON_DEPTH = 128;

	public static function boot(): void {
		add_action( 'admin_post_cb_core_mail_template_save', [ __CLASS__, 'save' ] );
		add_action( 'wp_ajax_cb_core_mail_template_save', [ __CLASS__, 'save_ajax' ] );
		add_action( 'admin_post_cb_core_mail_template_reset', [ __CLASS__, 'reset' ] );
	}

	public static function save(): void {
		self::guard( 'cb_core_mail_template_save' );
		$template_id = self::template_id();
		$result = self::persist( $template_id );
		if ( ! $result['success'] ) {
			self::fail( $result['message'], $template_id );
		}

		self::set_result( 'success', $result['message'] );
		self::redirect( $template_id );
	}

	public static function save_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to manage mail templates.', 'core-blueprint' ) ], 403 );
		}
		check_ajax_referer( 'cb_core_mail_template_save' );

		$result = self::persist( self::template_id() );
		if ( ! $result['success'] ) {
			wp_send_json_error( [ 'message' => $result['message'] ], $result['status'] );
		}

		wp_send_json_success(
			[ 'message' => $result['message'] ],
			200,
			JSON_INVALID_UTF8_SUBSTITUTE
		);
	}

	public static function reset(): void {
		self::guard( 'cb_core_mail_template_reset' );
		$template_id = self::template_id();
		if ( ! DesignerState::is_enabled() || ! TemplateRepository::reset( $template_id ) ) {
			self::fail( __( 'The mail template could not be reset.', 'core-blueprint' ), $template_id );
		}

		AuditLog::log( 'mail_template_reset', 'warning', [ 'template_id' => $template_id ] );
		self::set_result( 'success', __( 'Mail template reset to its current provider default.', 'core-blueprint' ) );
		self::redirect( $template_id );
	}

	/** @return array{success:bool,message:string,status:int} */
	private static function persist( string $template_id ): array {
		if ( ! DesignerState::is_enabled() || null === TemplateRegistry::get( $template_id ) ) {
			return [
				'success' => false,
				'message' => __( 'Mail Designer is disabled or the template is unavailable.', 'core-blueprint' ),
				'status'  => 409,
			];
		}

		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$json = isset( $_POST['project_json'] ) ? (string) wp_unslash( $_POST['project_json'] ) : '';
		try {
			$project = json_decode( $json, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			$project = null;
		}

		if ( ! is_array( $project ) || array_is_list( $project ) || ! TemplateRepository::save( $template_id, $subject, $project ) ) {
			return [
				'success' => false,
				'message' => __( 'The mail template could not be saved. Review the design and try again.', 'core-blueprint' ),
				'status'  => 422,
			];
		}

		AuditLog::log( 'mail_template_saved', 'notice', [ 'template_id' => $template_id ] );
		return [
			'success' => true,
			'message' => __( 'Mail template saved.', 'core-blueprint' ),
			'status'  => 200,
		];
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage mail templates.', 'core-blueprint' ), esc_html__( 'Forbidden', 'core-blueprint' ), [ 'response' => 403 ] );
		}
		check_admin_referer( $action );
	}

	private static function template_id(): string {
		$raw = isset( $_POST['template_id'] ) ? (string) wp_unslash( $_POST['template_id'] ) : '';
		return 1 === preg_match( '/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/', $raw ) ? $raw : '';
	}

	private static function fail( string $message, string $template_id ): never {
		self::set_result( 'error', $message );
		self::redirect( $template_id );
	}

	private static function set_result( string $type, string $message ): void {
		set_transient( self::RESULT_PREFIX . get_current_user_id(), [ 'type' => $type, 'message' => $message ], MINUTE_IN_SECONDS );
	}

	private static function redirect( string $template_id ): never {
		$url = admin_url( 'admin.php?page=' . Page::SLUG . '&tab=templates' );
		if ( '' !== $template_id ) {
			$url = add_query_arg( 'template', $template_id, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	private function __construct() {}
}
