<?php
declare(strict_types=1);
/**
 * Secured admin-post entrypoints for Core Setup review metadata.
 *
 * Browser requests never submit or persist evidence fingerprints. Every action
 * resolves current evidence server-side through ReviewManager.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Admin;

use CB\Core\Setup\Registry;
use CB\Core\Setup\ReviewManager;
use CB\Core\Setup\SectionRegistry;

defined( 'ABSPATH' ) || exit;

final class Actions {

	private const RESULT_PREFIX = 'cb_core_setup_result_';

	public static function boot(): void {
		add_action( 'admin_post_cb_core_setup_review', [ __CLASS__, 'review' ] );
		add_action( 'admin_post_cb_core_setup_clear', [ __CLASS__, 'clear' ] );
		add_action( 'admin_post_cb_core_setup_note', [ __CLASS__, 'note' ] );
	}

	public static function review(): void {
		self::guard( 'cb_core_setup_review' );

		$check_id = isset( $_POST['check_id'] ) ? sanitize_key( wp_unslash( $_POST['check_id'] ) ) : '';
		$disposition = isset( $_POST['disposition'] ) ? sanitize_key( wp_unslash( $_POST['disposition'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? (string) wp_unslash( $_POST['reason'] ) : '';

		self::guard_check( $check_id );

		try {
			$changed = ReviewManager::record( $check_id, $disposition, $reason, get_current_user_id() );
			self::set_result( 'success', $changed ? 'Setup review updated.' : 'Setup review is already current.' );
		} catch ( \InvalidArgumentException $e ) {
			self::set_result( 'error', $e->getMessage() );
		} catch ( \RuntimeException $e ) {
			self::set_result( 'error', 'Setup review could not be updated from the current site state.' );
		}

		self::redirect();
	}

	public static function clear(): void {
		self::guard( 'cb_core_setup_clear' );

		$check_id = isset( $_POST['check_id'] ) ? sanitize_key( wp_unslash( $_POST['check_id'] ) ) : '';
		self::guard_check( $check_id );

		try {
			$changed = ReviewManager::clear( $check_id );
			self::set_result( 'success', $changed ? 'Setup review cleared.' : 'This setup check had no stored review.' );
		} catch ( \RuntimeException $e ) {
			self::set_result( 'error', 'Setup review could not be cleared.' );
		}

		self::redirect();
	}

	public static function note(): void {
		self::guard( 'cb_core_setup_note' );

		$section_id = isset( $_POST['section_id'] ) ? sanitize_key( wp_unslash( $_POST['section_id'] ) ) : '';
		$note = isset( $_POST['note'] ) ? (string) wp_unslash( $_POST['note'] ) : '';

		if ( ! SectionRegistry::is_known( $section_id ) || ! SectionRegistry::can_manage_note( $section_id ) ) {
			wp_die( 'You do not have permission to manage this Core Setup section.', 'Forbidden', [ 'response' => 403 ] );
		}

		try {
			$changed = ReviewManager::save_section_note( $section_id, $note, get_current_user_id() );
			self::set_result( 'success', $changed ? 'Section note updated.' : 'Section note is unchanged.' );
		} catch ( \RuntimeException $e ) {
			self::set_result( 'error', 'Section note could not be updated.' );
		}

		self::redirect();
	}

	public static function pull_result(): ?array {
		$key = self::RESULT_PREFIX . get_current_user_id();
		$result = get_transient( $key );
		delete_transient( $key );
		return is_array( $result ) ? $result : null;
	}

	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to manage Core Setup.', 'Forbidden', [ 'response' => 403 ] );
		}
		check_admin_referer( $nonce_action );
	}

	private static function guard_check( string $check_id ): void {
		$check = Registry::get( $check_id );
		if ( null === $check ) {
			wp_die( 'Unknown Core Setup check.', 'Invalid request', [ 'response' => 400 ] );
		}
		if ( ! current_user_can( $check->capability() ) ) {
			wp_die( 'You do not have permission to review this Core Setup check.', 'Forbidden', [ 'response' => 403 ] );
		}
	}

	private static function set_result( string $type, string $message ): void {
		set_transient(
			self::RESULT_PREFIX . get_current_user_id(),
			[ 'type' => sanitize_key( $type ), 'message' => sanitize_text_field( $message ) ],
			MINUTE_IN_SECONDS
		);
	}

	private static function redirect(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . Page::SLUG ) );
		exit;
	}

	private function __construct() {}
}
