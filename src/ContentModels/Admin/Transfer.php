<?php
declare(strict_types=1);
/**
 * Admin handlers for Content Models JSON schema transfer.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\ContentModels\Admin;

use CB\Core\Admin\MutationAcknowledgement;
use CB\Core\ContentModels\Repository;
use CB\Core\ContentModels\SchemaTransfer;
use CB\Core\ContentModels\State;
use CB\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Transfer {
	private const PREVIEW_TTL = 600;
	private const ACKNOWLEDGEMENT_FIELD = 'content_models_import_acknowledgement';

	public static function boot(): void {
		add_action( 'admin_post_cb_core_content_models_export_schema', [ __CLASS__, 'export' ] );
		add_action( 'admin_post_cb_core_content_models_preview_import', [ __CLASS__, 'preview_import' ] );
		add_action( 'admin_post_cb_core_content_models_apply_import', [ __CLASS__, 'apply_import' ] );
	}

	public static function export(): void {
		self::guard( 'cb_core_content_models_export_schema' );
		$document = SchemaTransfer::export_document();
		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log( 'content_models_schema_exported', 'notice', [
				'post_types' => count( (array) $document['post_types'] ),
				'taxonomies' => count( (array) $document['taxonomies'] ),
				'option_pages' => count( (array) $document['option_pages'] ),
				'field_groups' => count( (array) $document['field_groups'] ),
			] );
		}
		$filename = 'core-blueprint-content-models-' . gmdate( 'Y-m-d-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	public static function preview_import(): void {
		self::guard( 'cb_core_content_models_preview_import' );
		try {
			if ( empty( $_FILES['schema_file'] ) || ! is_array( $_FILES['schema_file'] ) ) {
				throw new \InvalidArgumentException( __( 'Choose a Content Models JSON file.', 'core-blueprint' ) );
			}
			$file = $_FILES['schema_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- upload metadata validated below.
			if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				throw new \InvalidArgumentException( __( 'The schema file could not be uploaded.', 'core-blueprint' ) );
			}
			$size = (int) ( $file['size'] ?? 0 );
			$tmp = (string) ( $file['tmp_name'] ?? '' );
			if ( $size <= 0 || $size > 5 * MB_IN_BYTES || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
				throw new \InvalidArgumentException( __( 'The schema file is empty or exceeds the 5 MB import limit.', 'core-blueprint' ) );
			}
			$json = file_get_contents( $tmp );
			if ( ! is_string( $json ) ) {
				throw new \InvalidArgumentException( __( 'The schema file could not be read.', 'core-blueprint' ) );
			}
			$document = SchemaTransfer::decode( $json );
			$analysis = SchemaTransfer::analyze( $document );
			set_transient( self::preview_key(), [ 'document' => $document, 'analysis' => $analysis ], self::PREVIEW_TTL );
			self::redirect( [ 'cb_cm_import_preview' => '1' ] );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( [ 'cb_cm_import_error' => rawurlencode( $e->getMessage() ) ] );
		}
	}

	public static function apply_import(): void {
		self::guard( 'cb_core_content_models_apply_import' );
		$preview = get_transient( self::preview_key() );
		if ( ! is_array( $preview ) || ! is_array( $preview['document'] ?? null ) ) {
			self::redirect( [ 'cb_cm_import_error' => rawurlencode( __( 'The import preview expired. Upload the JSON file again.', 'core-blueprint' ) ) ] );
		}
		try {
			MutationAcknowledgement::require_confirmed(
				$_POST[ self::ACKNOWLEDGEMENT_FIELD ] ?? null, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce; strict literal confirmation only.
				__( 'Confirm your responsibility for backup and recovery before importing the Content Models schema.', 'core-blueprint' )
			);
			$overwrite = isset( $_POST['overwrite'] ) && '1' === (string) wp_unslash( $_POST['overwrite'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
			if ( class_exists( AuditLog::class ) ) {
				AuditLog::log( 'content_models_schema_import_acknowledged', 'notice', [
					'overwrite'                => $overwrite,
					'counts'                   => (array) ( $preview['analysis']['counts'] ?? [] ),
					'recovery_responsibility' => true,
				] );
			}
			$counts = SchemaTransfer::import( $preview['document'], $overwrite );
			delete_transient( self::preview_key() );
			if ( class_exists( AuditLog::class ) ) {
				AuditLog::log( 'content_models_schema_imported', 'warning', [ 'counts' => $counts, 'overwrite' => $overwrite ] );
			}
			self::redirect( [ 'cb_cm_imported' => '1' ] );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( [ 'cb_cm_import_error' => rawurlencode( $e->getMessage() ), 'cb_cm_import_preview' => '1' ] );
		}
	}

	/** @return array<string,mixed>|null */
	public static function current_preview(): ?array {
		$value = get_transient( self::preview_key() );
		return is_array( $value ) ? $value : null;
	}

	private static function guard( string $action ): void {
		if ( ! State::is_enabled() || ! current_user_can( 'cb_manage_content_models' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Content Models.', 'core-blueprint' ), esc_html__( 'Forbidden', 'core-blueprint' ), [ 'response' => 403 ] );
		}
		check_admin_referer( $action );
	}

	private static function preview_key(): string {
		return 'cb_cm_schema_import_' . get_current_user_id();
	}

	/** @param array<string,string> $args */
	private static function redirect( array $args ): void {
		$url = add_query_arg( array_merge( [ 'page' => Page::SLUG, 'tab' => 'tools' ], $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
