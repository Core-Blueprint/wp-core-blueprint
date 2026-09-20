<?php
declare(strict_types=1);

namespace CB\Core\Snippets\Admin;

use CB\Core\Admin\MutationAcknowledgement;
use CB\Core\Log\AuditLog;
use CB\Core\Snippets\ImportExport\Exporter;
use CB\Core\Snippets\ImportExport\Importer;
use CB\Core\Snippets\Repository;
use CB\Core\Snippets\State;

defined( 'ABSPATH' ) || exit;

final class Actions {
	private const RESULT_PREFIX = 'cb_core_snippets_result_';
	private const RESTORE_ACKNOWLEDGEMENT_FIELD = 'snippets_restore_acknowledgement';

	public static function boot(): void {
		add_action( 'admin_post_cb_core_snippets_save', [ __CLASS__, 'save' ] );
		add_action( 'admin_post_cb_core_snippets_toggle', [ __CLASS__, 'toggle' ] );
		add_action( 'admin_post_cb_core_snippets_duplicate', [ __CLASS__, 'duplicate' ] );
		add_action( 'admin_post_cb_core_snippets_delete', [ __CLASS__, 'delete' ] );
		add_action( 'admin_post_cb_core_snippets_export', [ __CLASS__, 'export' ] );
		add_action( 'admin_post_cb_core_snippets_import', [ __CLASS__, 'import' ] );
	}

	public static function save(): void {
		self::guard( 'cb_core_snippets_save', true );
		$id = isset( $_POST['snippet_id'] ) ? sanitize_key( wp_unslash( $_POST['snippet_id'] ) ) : '';
		$code = isset( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : '';
		$input = [
			'id'          => $id,
			'title'       => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '',
			'description' => isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '',
			'type'        => isset( $_POST['type'] ) ? wp_unslash( $_POST['type'] ) : 'php',
			'location'    => isset( $_POST['location'] ) ? wp_unslash( $_POST['location'] ) : '',
			'priority'    => isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 10,
			'enabled'     => isset( $_POST['enabled'] ),
			'shortcode'   => isset( $_POST['shortcode'] ) ? wp_unslash( $_POST['shortcode'] ) : '',
			'tags'        => isset( $_POST['tags'] ) ? wp_unslash( $_POST['tags'] ) : '',
			'conditions'  => self::conditions_from_request(),
		];

		$was_new = '' === $id;
		$saved = Repository::save( $input, $code );
		if ( is_wp_error( $saved ) ) {
			// The browser keeps a short-lived tab-local recovery copy. Never write
			// raw snippet code into WordPress options/transients on save failure.
			self::set_result( 'error', $saved->get_error_message() );
			self::redirect( 'snippets', [ 'view' => 'edit' ] + ( '' !== $id ? [ 'snippet' => $id ] : [] ) );
		}

		AuditLog::log(
			$was_new ? 'snippet_created' : 'snippet_updated',
			'notice',
			[
				'snippet_id' => (string) $saved['id'],
				'type'       => (string) $saved['type'],
				'enabled'    => ! empty( $saved['enabled'] ),
			]
		);
		self::set_result( 'success', $was_new ? __( 'Snippet created.', 'core-blueprint' ) : __( 'Snippet saved.', 'core-blueprint' ) );
		self::redirect( 'snippets', [ 'view' => 'edit', 'snippet' => (string) $saved['id'] ] );
	}

	public static function toggle(): void {
		self::guard( 'cb_core_snippets_toggle', true );
		$id = isset( $_POST['snippet_id'] ) ? sanitize_key( wp_unslash( $_POST['snippet_id'] ) ) : '';
		$current = Repository::get( $id );
		if ( null === $current ) {
			self::fail( __( 'Snippet not found.', 'core-blueprint' ) );
		}
		$enabled = empty( $current['enabled'] );
		$result = Repository::set_enabled( $id, $enabled );
		if ( is_wp_error( $result ) ) {
			self::fail( $result->get_error_message() );
		}
		AuditLog::log( $enabled ? 'snippet_enabled' : 'snippet_disabled', 'notice', [ 'snippet_id' => $id ] );
		self::set_result( 'success', $enabled ? __( 'Snippet enabled.', 'core-blueprint' ) : __( 'Snippet disabled.', 'core-blueprint' ) );
		self::redirect( 'snippets' );
	}

	public static function duplicate(): void {
		self::guard( 'cb_core_snippets_duplicate', true );
		$id = isset( $_POST['snippet_id'] ) ? sanitize_key( wp_unslash( $_POST['snippet_id'] ) ) : '';
		$result = Repository::duplicate( $id );
		if ( is_wp_error( $result ) ) {
			self::fail( $result->get_error_message() );
		}
		AuditLog::log( 'snippet_duplicated', 'notice', [ 'source_id' => $id, 'snippet_id' => (string) $result['id'] ] );
		self::set_result( 'success', __( 'Snippet duplicated as a disabled copy.', 'core-blueprint' ) );
		self::redirect( 'snippets', [ 'view' => 'edit', 'snippet' => (string) $result['id'] ] );
	}

	public static function delete(): void {
		self::guard( 'cb_core_snippets_delete', true );
		$id = isset( $_POST['snippet_id'] ) ? sanitize_key( wp_unslash( $_POST['snippet_id'] ) ) : '';
		$result = Repository::delete( $id );
		if ( is_wp_error( $result ) ) {
			self::fail( $result->get_error_message() );
		}
		AuditLog::log( 'snippet_deleted', 'warning', [ 'snippet_id' => $id ] );
		self::set_result( 'success', __( 'Snippet deleted.', 'core-blueprint' ) );
		self::redirect( 'snippets' );
	}

	public static function export(): void {
		// Read-only portability remains intentionally available while Snippets is
		// disabled so operators can recover/export stored code without re-enabling execution.
		self::guard( 'cb_core_snippets_export', false, false );
		$ids = isset( $_POST['snippet_ids'] ) && is_array( $_POST['snippet_ids'] )
			? array_map( static fn( $id ) => sanitize_key( wp_unslash( $id ) ), $_POST['snippet_ids'] )
			: [];
		$data = Exporter::build( $ids );
		AuditLog::log( 'snippets_exported', 'notice', [ 'count' => (int) $data['snippets_count'] ] );

		$filename = 'core-blueprint-snippets-' . (int) $data['snippets_count'] . '-' . gmdate( 'Y-m-d-H-i' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- controlled JSON download.
		exit;
	}

	public static function import(): void {
		self::guard( 'cb_core_snippets_import', true );
		$overwrite = isset( $_POST['overwrite'] ) && '1' === (string) wp_unslash( $_POST['overwrite'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		if ( $overwrite ) {
			try {
				MutationAcknowledgement::require_confirmed(
					$_POST[ self::RESTORE_ACKNOWLEDGEMENT_FIELD ] ?? null, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce; strict literal confirmation only.
					__( 'Confirm your responsibility for backup and recovery before restoring snippets with preserved IDs.', 'core-blueprint' )
				);
			} catch ( \InvalidArgumentException $error ) {
				self::fail( $error->getMessage(), 'import-export' );
			}
		}
		if ( empty( $_FILES['snippets_file'] ) || ! is_array( $_FILES['snippets_file'] ) ) {
			self::fail( __( 'Choose a JSON file to import.', 'core-blueprint' ), 'import-export' );
		}
		$file = $_FILES['snippets_file'];
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			self::fail( __( 'The snippets file could not be uploaded.', 'core-blueprint' ), 'import-export' );
		}
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) || 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			self::fail( __( 'Only a valid uploaded JSON file can be imported.', 'core-blueprint' ), 'import-export' );
		}
		$size = (int) ( $file['size'] ?? 0 );
		if ( $size < 1 || $size > 2 * MB_IN_BYTES ) {
			self::fail( __( 'The snippets import file must be smaller than 2 MB.', 'core-blueprint' ), 'import-export' );
		}
		$json = file_get_contents( $tmp );
		if ( ! is_string( $json ) ) {
			self::fail( __( 'The uploaded JSON file could not be read.', 'core-blueprint' ), 'import-export' );
		}

		if ( $overwrite ) {
			AuditLog::log( 'snippets_restore_acknowledged', 'notice', [
				'preserve_ids'            => true,
				'recovery_responsibility' => true,
			] );
		}

		$result = Importer::import_json( $json, $overwrite );
		AuditLog::log( 'snippets_imported', 'notice', [
			'source'  => (string) $result['source'],
			'created' => (int) $result['created'],
			'skipped' => (int) $result['skipped'],
		] );

		$message = sprintf(
			/* translators: 1: imported count, 2: skipped count */
			__( 'Import complete: %1$d created, %2$d skipped. Imported snippets remain disabled until reviewed.', 'core-blueprint' ),
			(int) $result['created'],
			(int) $result['skipped']
		);
		if ( ! empty( $result['errors'] ) ) {
			$message .= ' ' . implode( ' ', array_slice( array_map( 'sanitize_text_field', $result['errors'] ), 0, 3 ) );
		}
		self::set_result( empty( $result['errors'] ) ? 'success' : 'warning', $message );
		self::redirect( 'import-export' );
	}

	public static function pull_result(): ?array {
		$key = self::RESULT_PREFIX . get_current_user_id();
		$result = get_transient( $key );
		delete_transient( $key );
		return is_array( $result ) ? $result : null;
	}

	private static function conditions_from_request(): array {
		$rules = [];
		$scope = isset( $_POST['condition_scope'] ) ? sanitize_key( wp_unslash( $_POST['condition_scope'] ) ) : 'any';
		if ( in_array( $scope, [ 'frontend', 'admin' ], true ) ) {
			$rules[] = [ 'field' => 'scope', 'operator' => 'is', 'value' => $scope ];
		}
		$login = isset( $_POST['condition_login'] ) ? sanitize_key( wp_unslash( $_POST['condition_login'] ) ) : 'any';
		if ( 'logged_in' === $login ) {
			$rules[] = [ 'field' => 'logged_in', 'operator' => 'is', 'value' => '1' ];
		} elseif ( 'logged_out' === $login ) {
			$rules[] = [ 'field' => 'logged_in', 'operator' => 'is', 'value' => '0' ];
		}
		$role = isset( $_POST['condition_role'] ) ? sanitize_key( wp_unslash( $_POST['condition_role'] ) ) : '';
		if ( '' !== $role && get_role( $role ) ) {
			$rules[] = [ 'field' => 'user_role', 'operator' => 'is', 'value' => $role ];
		}
		$post_type = isset( $_POST['condition_post_type'] ) ? sanitize_key( wp_unslash( $_POST['condition_post_type'] ) ) : '';
		if ( '' !== $post_type ) {
			$rules[] = [ 'field' => 'post_type', 'operator' => 'is', 'value' => $post_type ];
		}
		$path = isset( $_POST['condition_path'] ) ? sanitize_text_field( wp_unslash( $_POST['condition_path'] ) ) : '';
		if ( '' !== $path ) {
			$rules[] = [ 'field' => 'request_path', 'operator' => 'contains', 'value' => $path ];
		}
		return [ 'relation' => 'and', 'rules' => $rules ];
	}

	private static function guard( string $nonce_action, bool $requires_file_mods, bool $requires_enabled = true ): void {
		if ( ! current_user_can( 'cb_manage_snippets' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage code snippets.', 'core-blueprint' ), esc_html__( 'Forbidden', 'core-blueprint' ), [ 'response' => 403 ] );
		}
		check_admin_referer( $nonce_action );
		if ( $requires_enabled && ! State::is_enabled() ) {
			wp_die(
				esc_html__( 'Snippets is disabled. Enable it from the Dashboard before changing managed snippets.', 'core-blueprint' ),
				esc_html__( 'Snippets disabled', 'core-blueprint' ),
				[ 'response' => 409 ]
			);
		}
		if ( $requires_file_mods && function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'capability_update_core' ) ) {
			wp_die( esc_html__( 'File modifications are disabled by this WordPress installation. Existing snippets can run, but managed snippet files cannot be changed.', 'core-blueprint' ), esc_html__( 'File modifications disabled', 'core-blueprint' ), [ 'response' => 403 ] );
		}
	}

	private static function fail( string $message, string $tab = 'snippets' ): void {
		self::set_result( 'error', $message );
		self::redirect( $tab );
	}

	private static function set_result( string $type, string $message ): void {
		set_transient( self::RESULT_PREFIX . get_current_user_id(), [ 'type' => $type, 'message' => $message ], MINUTE_IN_SECONDS );
	}

	private static function redirect( string $tab, array $args = [] ): void {
		$url = add_query_arg( array_merge( [ 'page' => Page::SLUG, 'tab' => sanitize_key( $tab ) ], $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
