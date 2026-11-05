<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\DataExchange;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Internal, actor-bound private staging for one verified resource package. */
final class ResourcePackageStage {
	private const MAX_PACKAGE_BYTES = 24 * 1024 * 1024;
	private const TTL_SECONDS = 900;
	private const TOKEN_VERSION = 1;

	/**
	 * Copy a local upload into a pre-provisioned private (0700) directory.
	 * Route authorization/nonce and consumer-specific policy remain with the caller.
	 * @return string|WP_Error Opaque short-lived ticket, never a public download URL.
	 */
	public static function stage( string $source, string $private_root, string $destination, string $capability ): string|WP_Error {
		$root = self::private_root( $private_root );
		$actor = get_current_user_id();
		if ( null === $root || $actor < 1 || ! self::valid_id( $destination ) || ! self::valid_id( $capability )
			|| ! current_user_can( $capability ) || '' === $source || is_link( $source ) || ! is_file( $source ) || ! is_readable( $source ) ) {
			return self::refuse();
		}
		$size = filesize( $source );
		if ( ! is_int( $size ) || $size < 22 || $size > self::MAX_PACKAGE_BYTES ) {
			return self::refuse();
		}
		try {
			$id = bin2hex( random_bytes( 24 ) );
		} catch ( \Throwable ) {
			return self::refuse();
		}
		self::prune_expired( $root );
		$path = $root . '/' . $id . '.zip';
		$previous_umask = umask( 0077 );
		try {
			$output = @fopen( $path, 'xb' );
		} finally {
			umask( $previous_umask );
		}
		if ( false === $output ) {
			return self::refuse();
		}
		$complete = false;
		try {
			$input = @fopen( $source, 'rb' );
			if ( false === $input ) {
				return self::refuse();
			}
			try {
				$copied = stream_copy_to_stream( $input, $output, self::MAX_PACKAGE_BYTES + 1 );
			} finally {
				fclose( $input );
			}
			if ( ! is_int( $copied ) || $copied !== $size || $copied > self::MAX_PACKAGE_BYTES ) {
				return self::refuse();
			}
			if ( ! fflush( $output ) || ! chmod( $path, 0600 ) ) {
				return self::refuse();
			}
			fclose( $output );
			$output = false;
			$verified = ResourcePackageVerifier::verify_file( $path );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			$payload = [
				'v' => self::TOKEN_VERSION,
				'id' => $id,
				'actor' => $actor,
				'destination' => $destination,
				'capability' => $capability,
				'expires' => time() + self::TTL_SECONDS,
				'sha256' => $verified['package_sha256'],
			];
			$encoded = self::base64url( json_encode( $payload, JSON_THROW_ON_ERROR ) );
			$signature = self::sign( $encoded );
			if ( null === $signature ) {
				return self::refuse();
			}
			$complete = true;
			return $encoded . '.' . $signature;
		} catch ( \Throwable ) {
			return self::refuse();
		} finally {
			if ( is_resource( $output ) ) {
				fclose( $output );
			}
			if ( ! $complete ) {
				@unlink( $path );
			}
		}
	}

	/**
	 * Re-open only the pinned, verified bytes for the current actor/destination.
	 * Call again at apply (not just preview); a projection is not authorization.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function inspect( string $ticket, string $private_root, string $destination ): array|WP_Error {
		$root = self::private_root( $private_root );
		$payload = self::authorize_ticket( $ticket, $destination );
		if ( null === $root || null === $payload || time() >= $payload['expires'] ) {
			return self::refuse();
		}
		$path = $root . '/' . $payload['id'] . '.zip';
		if ( is_link( $path ) || ! is_file( $path ) || 0 !== ( fileperms( $path ) & 0077 ) ) {
			return self::refuse();
		}
		$verified = ResourcePackageVerifier::verify_file( $path );
		if ( is_wp_error( $verified ) || ! hash_equals( $payload['sha256'], $verified['package_sha256'] ) ) {
			return self::refuse();
		}
		return $verified;
	}

	/** Cleanup only the current actor's signed staging identity. */
	public static function discard( string $ticket, string $private_root, string $destination ): bool {
		$root = self::private_root( $private_root );
		$payload = self::authorize_ticket( $ticket, $destination );
		if ( null === $root || null === $payload ) {
			return false;
		}
		$path = $root . '/' . $payload['id'] . '.zip';
		return ! is_link( $path ) && is_file( $path ) && @unlink( $path );
	}

	/** Bounded opportunistic cleanup. Call from an authenticated cron/job as well. */
	public static function prune_expired( string $private_root, int $max_entries = 100 ): int {
		$root = self::private_root( $private_root );
		if ( null === $root || $max_entries < 1 || $max_entries > 1000 ) {
			return 0;
		}
		$pruned = 0;
		$seen = 0;
		try {
			foreach ( new \DirectoryIterator( $root ) as $file ) {
				if ( ++$seen > $max_entries ) {
					break;
				}
				if ( ! $file->isFile() || $file->isLink()
					|| 1 !== preg_match( '/^[a-f0-9]{48}\.zip$/D', $file->getFilename() )
					|| $file->getMTime() > time() - self::TTL_SECONDS - 300 ) {
					continue;
				}
				if ( @unlink( $file->getPathname() ) ) {
					++$pruned;
				}
			}
		} catch ( \Throwable ) {
			return $pruned;
		}
		return $pruned;
	}

	/** @return array{id:string,actor:int,destination:string,capability:string,expires:int,sha256:string,v:int}|null */
	private static function authorize_ticket( string $ticket, string $destination ): ?array {
		$parts = explode( '.', $ticket );
		if ( 2 !== count( $parts ) || strlen( $ticket ) > 1024 || ! self::valid_id( $destination ) ) {
			return null;
		}
		$signature = self::sign( $parts[0] );
		if ( null === $signature || ! hash_equals( $signature, $parts[1] ) ) {
			return null;
		}
		$bytes = base64_decode( strtr( $parts[0], '-_', '+/' ), true );
		if ( false === $bytes ) {
			return null;
		}
		try {
			$payload = json_decode( $bytes, true, 8, JSON_THROW_ON_ERROR );
		} catch ( \Throwable ) {
			return null;
		}
		return is_array( $payload ) && count( $payload ) === 7
			&& self::TOKEN_VERSION === ( $payload['v'] ?? null )
			&& is_string( $payload['id'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{48}$/D', $payload['id'] )
			&& is_int( $payload['actor'] ?? null ) && $payload['actor'] > 0 && $payload['actor'] === get_current_user_id()
			&& ( $payload['destination'] ?? null ) === $destination
			&& is_string( $payload['capability'] ?? null ) && self::valid_id( $payload['capability'] )
			&& current_user_can( $payload['capability'] )
			&& is_int( $payload['expires'] ?? null ) && $payload['expires'] > 0
			&& is_string( $payload['sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $payload['sha256'] )
			? $payload : null;
	}

	/** Verify caller-provisioned 0700 storage outside any public document roots. */
	private static function private_root( string $path ): ?string {
		$real = '' !== $path && ! is_link( $path ) ? realpath( $path ) : false;
		if ( false === $real || ! is_dir( $real ) || 0 !== ( fileperms( $real ) & 0077 ) ) {
			return null;
		}
		foreach ( [ ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '', $_SERVER['DOCUMENT_ROOT'] ?? '' ] as $public ) {
			$public_real = '' !== $public ? realpath( $public ) : false;
			if ( false !== $public_real && ( $real === $public_real || str_starts_with( $real, rtrim( $public_real, '/' ) . '/' ) ) {
				return null;
			}
		}
		return $real;
	}

	private static function sign( string $encoded ): ?string {
		if ( ! function_exists( 'wp_salt' ) ) {
			return null;
		}
		$key = (string) wp_salt( 'auth' ) . (string) wp_salt( 'secure_auth' );
		return strlen( $key ) >= 32 ? hash_hmac( 'sha256', 'cb-resource-stage-v1:' . $encoded, $key ) : null;
	}

	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	private static function valid_id( string $id ): bool {
		return strlen( $id ) <= 96 && 1 === preg_match( '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $id );
	}

	private static function refuse(): WP_Error {
		return new WP_Error( 'cb_core_resource_stage_unavailable', 'Template resource staging is unavailable or the ticket is invalid.' );
	}
}
