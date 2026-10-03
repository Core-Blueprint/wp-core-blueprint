<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Quarantine;

use CoreBlueprint\Core\Integrity\Support\PathGuard;
use RuntimeException;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
use function chmod;
// phpcs:enable WordPress.WP.AlternativeFunctions
use function dirname;
use function file_exists;
use function file_put_contents;
use function hash;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
use function mkdir;
// phpcs:enable WordPress.WP.AlternativeFunctions
use function preg_match;
use function realpath;
// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
use function rename;
// phpcs:enable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
use function rmdir;
// phpcs:enable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
use function unlink;
// phpcs:enable WordPress.WP.AlternativeFunctions
use function wp_normalize_path;

/** Private filesystem vault for Scanner quarantine payloads. */
final class Vault {
	public static function root(): string {
		$canonical_abspath = realpath( ABSPATH );
		$site_root = is_string( $canonical_abspath ) ? wp_normalize_path( $canonical_abspath ) : wp_normalize_path( ABSPATH );
		$parent = dirname( rtrim( $site_root, '/' ) );
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : false;
		$document_root = is_string( $document_root ) ? rtrim( wp_normalize_path( $document_root ), '/' ) : '';
		if ( '' !== $document_root && ( $parent === $document_root || PathGuard::is_inside( $parent, $document_root ) ) ) {
			$parent = dirname( $document_root );
		}
		$parent = (string) apply_filters( 'core_blueprint_quarantine_vault_parent', $parent, $site_root, $document_root );
		$id = substr( hash( 'sha256', $site_root ), 0, 20 );
		$root = wp_normalize_path( rtrim( $parent, '/' ) . '/.core-blueprint-quarantine/' . $id );
		self::ensure_root( $root, $site_root, $document_root );
		return $root;
	}

	public static function item_dir( string $id ): string {
		if ( 1 !== preg_match( '/^q_[a-f0-9]{16}$/', $id ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Invalid quarantine item identifier.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return self::root() . '/' . $id;
	}

	public static function payload_path( string $id, string $kind ): string {
		return self::item_dir( $id ) . ( 'directory' === $kind ? '/payload' : '/payload.bin' );
	}

	public static function prepare_item( string $id ): string {
		$dir = self::item_dir( $id );
		if ( file_exists( $dir ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Quarantine item already exists.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Could not create quarantine item directory.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
		@chmod( $dir, 0700 );
		// phpcs:enable WordPress.WP.AlternativeFunctions
		return $dir;
	}

	public static function move_in( string $source, string $id, string $kind, string $allowed_root ): string {
		$resolved_source = realpath( $source );
		$resolved_root   = realpath( $allowed_root );
		if ( ! is_string( $resolved_source ) || ! is_string( $resolved_root ) || is_link( $source ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The quarantine source could not be validated safely.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$resolved_source = wp_normalize_path( $resolved_source );
		$resolved_root   = rtrim( wp_normalize_path( $resolved_root ), '/' );
		if ( $resolved_source === $resolved_root || ! PathGuard::existing_path_is_inside( $resolved_source, $resolved_root ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The quarantine source is outside the allowed uploads root.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( ( 'file' === $kind && ! is_file( $resolved_source ) ) || ( 'directory' === $kind && ! is_dir( $resolved_source ) ) || ! in_array( $kind, [ 'file', 'directory' ], true ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The quarantine source type is invalid.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$source = $resolved_source;

		self::prepare_item( $id );
		$payload = self::payload_path( $id, $kind );
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
		if ( ! @rename( $source, $payload ) ) {
		// phpcs:enable WordPress.WP.AlternativeFunctions
			self::remove_tree( self::item_dir( $id ) );
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Could not atomically move the item into the private quarantine vault. No files were changed.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		self::restrict_tree( $payload );
		return $payload;
	}

	public static function restore( string $id, string $kind, string $destination, string $allowed_root = '' ): void {
		$payload = self::payload_path( $id, $kind );
		if ( ! file_exists( $payload ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Quarantine payload is missing.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$destination = wp_normalize_path( $destination );
		if ( '' !== $allowed_root ) {
			self::assert_restore_destination( $destination, $allowed_root );
		}

		if ( file_exists( $destination ) || is_link( $destination ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The original location is no longer empty. Restore was refused to avoid overwriting data.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$parent = dirname( $destination );
		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) && ! is_dir( $parent ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Could not recreate the original parent directory.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// Revalidate immediately before the irreversible move. A parent path may
		// have changed after the Service-level evidence check, especially on a
		// shared writable filesystem. This does not pretend to eliminate every
		// possible TOCTOU race, but it keeps the Vault fail-closed at its own
		// filesystem boundary instead of trusting a caller's earlier observation.
		if ( '' !== $allowed_root ) {
			self::assert_restore_destination( $destination, $allowed_root );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
		if ( ! @rename( $payload, $destination ) ) {
		// phpcs:enable WordPress.WP.AlternativeFunctions
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Could not atomically restore the quarantine payload.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		self::remove_tree( self::item_dir( $id ) );
	}

	public static function delete_payload( string $id ): void {
		self::remove_tree( self::item_dir( $id ) );
		if ( file_exists( self::item_dir( $id ) ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Could not permanently remove the quarantine payload.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	private static function assert_restore_destination( string $destination, string $allowed_root ): void {
		$resolved_root = realpath( $allowed_root );
		if ( ! is_string( $resolved_root ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The restore root could not be validated.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$root = rtrim( wp_normalize_path( $resolved_root ), '/' );
		$destination = wp_normalize_path( $destination );
		if ( ! PathGuard::is_inside( $destination, $root ) || $destination === $root ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The restore destination is outside the allowed uploads root.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$parent = wp_normalize_path( dirname( $destination ) );
		$relative_parent = ltrim( substr( $parent, strlen( $root ) ), '/' );
		$cursor = $root;
		if ( '' !== $relative_parent ) {
			foreach ( explode( '/', $relative_parent ) as $segment ) {
				if ( '' === $segment || '.' === $segment || '..' === $segment ) {
					// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
					throw new RuntimeException( __( 'The restore parent path is unsafe.', 'core-blueprint' ) );
					// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
				$cursor .= '/' . $segment;
				if ( is_link( $cursor ) ) {
					// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
					throw new RuntimeException( __( 'The restore parent path contains a symlink and was refused.', 'core-blueprint' ) );
					// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
				if ( file_exists( $cursor ) && ! PathGuard::existing_path_is_inside( $cursor, $root ) ) {
					// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
					throw new RuntimeException( __( 'The restore parent path left the allowed uploads root.', 'core-blueprint' ) );
					// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
			}
		}

		if ( is_dir( $parent ) && ! PathGuard::existing_path_is_inside( $parent, $root ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The restore parent directory failed canonical uploads containment.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	private static function ensure_root( string $root, string $site_root, string $document_root = '' ): void {
		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) && ! is_dir( $root ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Could not create the Core Scanner quarantine vault.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
		@chmod( $root, 0700 );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		$resolved_root = realpath( $root );
		$resolved_site = realpath( $site_root );
		if ( ! is_string( $resolved_root ) || ! is_string( $resolved_site ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Could not validate the Core Scanner quarantine vault.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$resolved_root = wp_normalize_path( $resolved_root );
		$resolved_site = wp_normalize_path( $resolved_site );
		if ( PathGuard::is_inside( $resolved_root, $resolved_site ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The quarantine vault resolved inside WordPress and was refused.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( '' !== $document_root && ( $resolved_root === $document_root || PathGuard::is_inside( $resolved_root, $document_root ) ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'The quarantine vault resolved inside the public document root and was refused.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// Defense in depth for Apache/IIS if a non-standard hosting layout makes
		// the parent path web-addressable. Nginx is covered by keeping the default
		// vault outside the canonical WordPress document root.
		@file_put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n" );
		@file_put_contents( $root . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
	}

	private static function restrict_tree( string $path ): void {
		if ( is_file( $path ) ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
			@chmod( $path, 0600 );
			// phpcs:enable WordPress.WP.AlternativeFunctions
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			if ( $entry->isLink() ) {
				continue;
			}
			// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
			@chmod( $entry->getPathname(), $entry->isDir() ? 0700 : 0600 );
			// phpcs:enable WordPress.WP.AlternativeFunctions
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
		@chmod( $path, 0700 );
		// phpcs:enable WordPress.WP.AlternativeFunctions
	}

	public static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			wp_delete_file( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			if ( $entry->isLink() || $entry->isFile() ) {
				wp_delete_file( $entry->getPathname() );
			} else {
				// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
				@rmdir( $entry->getPathname() );
				// phpcs:enable WordPress.WP.AlternativeFunctions
			}
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Core Blueprint audited filesystem boundary: quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup.
		@rmdir( $path );
		// phpcs:enable WordPress.WP.AlternativeFunctions
	}
}
