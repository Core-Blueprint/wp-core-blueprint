<?php
declare(strict_types=1);

namespace CB\Core\Profiles\Admin;

use CB\Core\Admin\Pages\Profiles as ProfilesPage;
use CB\Core\Log\AuditLog;
use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Profiles\CanonicalJson;
use CB\Core\Profiles\Document;
use CB\Core\Profiles\Engine;
use CB\Core\Profiles\PreviewStore;
use CB\Core\Profiles\SectionRegistry;

defined( 'ABSPATH' ) || exit;

final class Actions {
	public const EXPORT_ACTION = 'cb_core_profile_export';
	public const PREVIEW_ACTION = 'cb_core_profile_preview';
	public const APPLY_ACTION = 'cb_core_profile_apply';
	public const NOTICE_PREFIX = 'cb_core_profile_notice_';

	public static function boot(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, [ self::class, 'export' ] );
		add_action( 'admin_post_' . self::PREVIEW_ACTION, [ self::class, 'preview' ] );
		add_action( 'admin_post_' . self::APPLY_ACTION, [ self::class, 'apply' ] );
	}

	public static function export(): void {
		self::require_operator();
		check_admin_referer( self::EXPORT_ACTION );
		$name = isset( $_POST['profile_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['profile_name'] ) ) : '';
		$description = isset( $_POST['profile_description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['profile_description'] ) ) : '';
		$sections = isset( $_POST['sections'] ) && is_array( $_POST['sections'] ) ? wp_unslash( $_POST['sections'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Engine validates canonical section IDs fail-closed.

		try {
			$document = Engine::export_document( $name, $description, $sections );
			AuditLog::log( 'profiles.exported', 'info', [
				'actor'        => self::actor(),
				'profile_name' => $name,
				'sections'     => array_keys( $document['sections'] ),
			] );
			$filename = sanitize_file_name( sanitize_title( $name ) . '.core-blueprint-profile.json' );
			if ( '' === $filename ) {
				$filename = 'core-blueprint-profile.json';
			}
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'X-Content-Type-Options: nosniff' );
			echo CanonicalJson::encode( $document, JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download body.
			exit;
		} catch ( \Throwable $error ) {
			self::redirect_with_notice( 'error', $error->getMessage() );
		}
	}

	public static function preview(): void {
		self::require_operator();
		check_admin_referer( self::PREVIEW_ACTION );
		try {
			$file = $_FILES['profile_file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
			if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				throw new \InvalidArgumentException( __( 'Choose a Core Blueprint Profile JSON file.', 'core-blueprint' ) );
			}
			$tmp = (string) ( $file['tmp_name'] ?? '' );
			$size = '' !== $tmp && is_file( $tmp ) ? filesize( $tmp ) : false;
			if ( false === $size || $size <= 0 || $size > Document::MAX_BYTES || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
				throw new \InvalidArgumentException( __( 'The uploaded profile file is invalid or too large.', 'core-blueprint' ) );
			}
			$json = file_get_contents( $tmp, false, null, 0, Document::MAX_BYTES + 1 );
			if ( false === $json ) {
				throw new \RuntimeException( __( 'Core Blueprint could not read the uploaded profile.', 'core-blueprint' ) );
			}
			$document = Document::decode( $json );
			$preview = Engine::preview( $document );
			$user_id = get_current_user_id();
			$normalized_document = $preview['document'];
			$stored_preview = $preview;
			unset( $stored_preview['snapshots'], $stored_preview['document'] );
			$token = PreviewStore::put( $user_id, $normalized_document, $stored_preview );
			AuditLog::log( 'profiles.previewed', 'info', [
				'actor'        => self::actor(),
				'profile_name' => (string) $document['profile']['name'],
				'sections'     => array_keys( $document['sections'] ),
				'change_count' => (int) $preview['total_changes'],
			] );
			wp_safe_redirect( add_query_arg( 'preview', rawurlencode( $token ), self::page_url() ) );
			exit;
		} catch ( \Throwable $error ) {
			self::redirect_with_notice( 'error', $error->getMessage() );
		}
	}

	public static function apply(): void {
		self::require_operator();
		check_admin_referer( self::APPLY_ACTION );
		$user_id = get_current_user_id();
		$token = isset( $_POST['preview_token'] ) ? (string) wp_unslash( $_POST['preview_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PreviewStore validates exact lowercase hex.
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked with wp_check_password, never persisted/logged.
		$record = PreviewStore::get( $user_id, $token );
		if ( null === $record ) {
			self::redirect_with_notice( 'error', __( 'The profile preview expired. Upload the profile again.', 'core-blueprint' ) );
		}
		$user = wp_get_current_user();
		if ( '' === $password || ! wp_check_password( $password, $user->user_pass, $user_id ) ) {
			AuditLog::log( 'security.password_reconfirm_failed', 'warning', [ 'user_login' => $user->user_login ] );
			self::redirect_with_notice( 'error', __( 'Password confirmation failed. The profile was not applied.', 'core-blueprint' ), $token );
		}

		try {
			$document = is_array( $record['document'] ?? null ) ? $record['document'] : [];
			$stored_preview = is_array( $record['preview'] ?? null ) ? $record['preview'] : [];
			$fingerprint = (string) ( $stored_preview['fingerprint'] ?? '' );
			$result = Engine::apply( $document, $fingerprint, self::actor() );
			PreviewStore::delete( $user_id, $token );
			self::redirect_with_notice( 'success', sprintf(
				/* translators: %d: number of changed settings */
				_n( 'Profile applied. %d configuration change was reviewed.', 'Profile applied. %d configuration changes were reviewed.', (int) $result['change_count'], 'core-blueprint' ),
				(int) $result['change_count']
			) );
		} catch ( \Throwable $error ) {
			PreviewStore::delete( $user_id, $token );
			self::redirect_with_notice( 'error', $error->getMessage() );
		}
	}

	private static function require_operator(): void {
		$user = wp_get_current_user();
		if (
			! current_user_can( 'cb_manage_permissions' )
			|| ! ( $user instanceof \WP_User )
			|| ! PrivilegedAccessGuard::is_trusted_operator( $user )
		) {
			wp_die(
				esc_html__( 'Only an approved Core Blueprint Operator may export or apply Core Profiles.', 'core-blueprint' ),
				esc_html__( 'Forbidden', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}
	}

	private static function actor(): string {
		return 'operator:' . get_current_user_id();
	}

	private static function page_url(): string {
		return admin_url( 'admin.php?page=' . ProfilesPage::SLUG );
	}

	private static function redirect_with_notice( string $type, string $message, string $preview_token = '' ): never {
		$message = substr( sanitize_text_field( $message ), 0, 1000 );
		set_transient( self::NOTICE_PREFIX . get_current_user_id(), [ 'type' => $type, 'message' => $message ], MINUTE_IN_SECONDS );
		$url = self::page_url();
		if ( '' !== $preview_token ) {
			$url = add_query_arg( 'preview', rawurlencode( $preview_token ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
