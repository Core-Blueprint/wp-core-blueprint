<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\DataExchange;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Base-owned HTTP transport for an optional, offline resource package.
 * No general filesystem API, consumer media writer or second import engine.
 * All domain permissions and destination writes remain with the Data Exchange
 * entity resolved from the exact verified envelope.
 */
final class ResourcePackageController {
	private const MAX_UPLOAD_BYTES = 24 * 1024 * 1024;
	private const CAPABILITY = 'manage_options';
	private const NAMESPACE = 'core-blueprint/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
		add_action( 'init', [ self::class, 'schedule_cleanup' ], 10 );
		add_action( 'core_blueprint_resource_package_cleanup', [ self::class, 'cleanup' ] );
		add_action( 'admin_post_cb_core_template_resource_export', [ self::class, 'download' ] );
	}

	/** Opportunistic per-request self-heal of the bounded hourly cleanup job. */
	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'core_blueprint_resource_package_cleanup' ) ) {
			wp_schedule_event( time() + 3600, 'hourly', 'core_blueprint_resource_package_cleanup' );
		}
	}

	public static function cleanup(): void {
		$root = self::private_root();
		if ( null !== $root ) {
			ResourcePackageStage::prune_expired( $root, 100 );
			ResourcePackageExport::prune_expired( $root, 100 );
		}
	}

	public static function clear_schedule(): void {
		wp_clear_scheduled_hook( 'core_blueprint_resource_package_cleanup' );
	}

	public static function routes(): void {
		foreach ( [ 'stage', 'preview', 'apply', 'discard' ] as $action ) {
			register_rest_route( self::NAMESPACE, '/template-resource-package/' . $action, [
				'methods'             => 'POST',
				'callback'            => [ self::class, $action ],
				'permission_callback' => [ self::class, 'permitted' ],
			] );
		}
	}

	public static function permitted( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return 'POST' === $request->get_method()
			&& is_user_logged_in()
			&& get_current_user_id() > 0
			&& current_user_can( self::CAPABILITY )
			&& is_string( $nonce ) && '' !== $nonce
			&& (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	private static function respond( string $status, int $http = 400, array $data = [] ): WP_REST_Response {
		$response = new WP_REST_Response( [ 'status' => $status ] + $data, $http );
		// Tickets and previews are authentication-scoped, never shared cache data.
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		return $response;
	}

	private static function valid_destination( mixed $id ): bool {
		return is_string( $id ) && strlen( $id ) <= 96
			&& 1 === preg_match( '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $id );
	}

	private static function exact( mixed $value, array $keys ): bool {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return false;
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		return $actual === $keys;
	}

	private static function json( WP_REST_Request $request, array $keys ): ?array {
		if ( strlen( $request->get_body() ) > 2048 ) {
			return null;
		}
		$data = $request->get_json_params();
		return self::exact( $data, $keys )
			&& self::valid_destination( $data['destinationId'] ?? null )
			&& is_string( $data['ticket'] ?? null )
			&& strlen( $data['ticket'] ) > 80
			&& strlen( $data['ticket'] ) <= 1024
			? $data : null;
	}

	/**
	 * Use only a directory below OS temporary storage, never a URL-backed
	 * uploads directory. Strict permissions and all public-root exclusions are
	 * checked again by ResourcePackageStage::private_root_path().
	 */
	private static function private_root(): ?string {
		$temp = sys_get_temp_dir();
		$base = is_string( $temp ) && '' !== $temp ? realpath( $temp ) : false;
		if ( false === $base || ! is_dir( $base ) || ! is_writable( $base ) ) {
			return null;
		}
		$identity = (string) ABSPATH . ':' . (string) get_current_blog_id();
		$salt = (string) wp_salt( 'auth' );
		if ( strlen( $salt ) < 32 ) {
			return null;
		}
		$folder = $base . '/cb-resource-package-' . substr( hash_hmac( 'sha256', $identity, $salt ), 0, 32 );
		if ( is_link( $folder ) ) {
			return null;
		}
		if ( ! is_dir( $folder ) ) {
			$previous_umask = umask( 0077 );
			try {
				$created = @mkdir( $folder, 0700 );
			} finally {
				umask( $previous_umask );
			}
			if ( ! $created && ! is_dir( $folder ) ) {
				return null;
			}
		}
		return ResourcePackageStage::private_root_path( $folder );
	}

	/** Authenticated multipart intake. Never trust browser filenames or MIME. */
	public static function stage( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::permitted( $request ) ) {
			return self::respond( 'refused', 403 );
		}
		$fields = $request->get_body_params();
		$files = $request->get_file_params();
		if ( ! self::exact( $fields, [ 'destinationId' ] )
			|| ! self::valid_destination( $fields['destinationId'] )
			|| ! self::exact( $files, [ 'package' ] )
			|| ! is_array( $files['package'] )
			|| ! is_string( $files['package']['tmp_name'] ?? null )
			|| ! is_int( $files['package']['error'] ?? null )
			|| UPLOAD_ERR_OK !== $files['package']['error']
			|| ! is_int( $files['package']['size'] ?? null )
			|| $files['package']['size'] < 22 || $files['package']['size'] > self::MAX_UPLOAD_BYTES
			|| ! is_uploaded_file( $files['package']['tmp_name'] ) ) {
			return self::respond( 'invalid' );
		}
		$root = self::private_root();
		if ( null === $root ) {
			return self::respond( 'unavailable', 503 );
		}
		$actor = get_current_user_id();
		$ticket = ResourcePackageStage::stage(
			$files['package']['tmp_name'], $root, $fields['destinationId'], self::CAPABILITY
		);
		if ( is_wp_error( $ticket ) || get_current_user_id() !== $actor
			|| ! self::permitted( $request ) ) {
			if ( is_string( $ticket ) ) {
				ResourcePackageStage::discard( $ticket, $root, $fields['destinationId'] );
			}
			return self::respond( 'invalid' );
		}
		return self::respond( 'ready', 200, [ 'ticket' => $ticket, 'expiresIn' => 900 ] );
	}

	/** Read-only, reauthorized canonical create-only preflight. */
	public static function preview( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::permitted( $request ) ) {
			return self::respond( 'refused', 403 );
		}
		$data = self::json( $request, [ 'destinationId', 'ticket' ] );
		if ( null === $data ) {
			return self::respond( 'invalid' );
		}
		$root = self::private_root();
		if ( null === $root ) {
			return self::respond( 'unavailable', 503 );
		}
		$actor = get_current_user_id();
		$result = ResourcePackageImport::preview( $data['ticket'], $root, $data['destinationId'] );
		if ( is_wp_error( $result ) || ! is_array( $result ) || true !== ( $result['valid'] ?? null )
			|| 1 !== ( $result['record_count'] ?? null )
			|| 1 !== ( $result['counts'][Foundation::OP_CREATE] ?? null )
			|| ! is_string( $result['fingerprint'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $result['fingerprint'] )
			|| ! self::permitted( $request ) || get_current_user_id() !== $actor ) {
			return self::respond( 'invalid' );
		}
		return self::respond( 'ready', 200, [ 'preview' => $result ] );
	}

	/** Canonical fingerprint re-plan and one provider-owned draft create. */
	public static function apply( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::permitted( $request ) ) {
			return self::respond( 'refused', 403 );
		}
		$data = self::json( $request, [ 'destinationId', 'ticket', 'fingerprint' ] );
		if ( null === $data || ! is_string( $data['fingerprint'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $data['fingerprint'] ) ) {
			return self::respond( 'invalid' );
		}
		$root = self::private_root();
		if ( null === $root ) {
			return self::respond( 'unavailable', 503 );
		}
		$actor = get_current_user_id();
		$result = ResourcePackageImport::apply(
			$data['ticket'], $root, $data['destinationId'], $data['fingerprint']
		);
		// A response may be lost AFTER a successful write. Never infer no-write
		// or invite a retry without a separate destination reconciliation.
		if ( ! self::permitted( $request ) || get_current_user_id() !== $actor
			|| is_wp_error( $result ) || ! is_array( $result )
			|| ( $result['status'] ?? null ) !== 'complete'
			|| ( $result['applied_count'] ?? null ) !== 1
			|| ( $result['record_count'] ?? null ) !== 1
			|| ! is_string( $result['items'][0]['reference'] ?? null )
			|| '' === $result['items'][0]['reference'] ) {
			return self::respond( 'unknown', 503 );
		}
		// Leave the ticket until explicit discard or expiry for reconciliation;
		// no automatic replay and no exposure of opaque plan/media internals.
		return self::respond( 'complete', 200, [
			'reference' => $result['items'][0]['reference'],
		] );
	}

	/** Explicit cleanup only for the current actor's signed ticket. */
	public static function discard( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::permitted( $request ) ) {
			return self::respond( 'refused', 403 );
		}
		$data = self::json( $request, [ 'destinationId', 'ticket' ] );
		if ( null === $data ) {
			return self::respond( 'invalid' );
		}
		$root = self::private_root();
		if ( null === $root ) {
			return self::respond( 'unavailable', 503 );
		}
		return ResourcePackageStage::discard( $data['ticket'], $root, $data['destinationId'] )
			? self::respond( 'discarded', 200 ) : self::respond( 'invalid' );
	}

	/**
	 * Authenticated, server-generated ZIP download over POST, not a public URL.
	 * No pre-generated file path, callback, entity ID or storage root is
	 * accepted from a browser. The provider must support the opt-in contract.
	 */
	public static function download(): void {
		$actor = get_current_user_id();
		$input = wp_unslash( $_POST );
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST'
			|| ! is_user_logged_in() || $actor < 1
			|| ! current_user_can( self::CAPABILITY )
			|| ! self::exact( $input, [ 'action', '_wpnonce', 'source', 'templateId', 'version' ] )
			|| ( $input['action'] ?? null ) !== 'cb_core_template_resource_export'
			|| ! is_string( $input['_wpnonce'] )
			|| ! wp_verify_nonce( $input['_wpnonce'], 'wp_rest' )
			|| ! self::valid_destination( $input['source'] )
			|| ! self::valid_destination( $input['templateId'] )
			|| ! is_string( $input['version'] )
			|| strlen( $input['version'] ) > 48
			|| 1 !== preg_match( '/^[1-9][0-9]*(?:\\.[0-9]+){0,2}$/D', $input['version'] )
			|| headers_sent() ) {
			wp_die( 'Template package export is unavailable.', '', [ 'response' => 403 ] );
		}
		$root = self::private_root();
		if ( null === $root ) {
			wp_die( 'Template package export is unavailable.', '', [ 'response' => 503 ] );
		}
		$package = \CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry::export_resource_package(
			$input['source'], $input['templateId'], $input['version'], $root
		);
		if ( is_wp_error( $package ) || ! is_array( $package ) ) {
			wp_die( 'Template package export is unavailable.', '', [ 'response' => 403 ] );
		}
		$path = $package['path'] ?? null;
		$digest = $package['package_sha256'] ?? null;
		$handle = null;
		$ready = false;
		$size = 0;
		try {
			if ( is_string( $path ) && is_string( $digest )
				&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $digest )
				&& 1 === preg_match( '~^' . preg_quote( $root, '~' ) . '/[a-f0-9]{48}\\.export\\.zip$~D', $path )
				&& ! is_link( $path ) && is_file( $path )
				&& 0 === ( fileperms( $path ) & 0077 )
				&& ! headers_sent() ) {
				$handle = @fopen( $path, 'rb' );
				$stat = is_resource( $handle ) ? fstat( $handle ) : false;
				$size = is_array( $stat ) ? ( $stat['size'] ?? 0 ) : 0;
				$current = @hash_file( 'sha256', $path );
				$ready = is_int( $size ) && $size > 0 && $size <= self::MAX_UPLOAD_BYTES
					&& is_string( $current ) && hash_equals( $digest, $current )
					&& get_current_user_id() === $actor && is_user_logged_in()
					&& current_user_can( self::CAPABILITY )
					&& wp_verify_nonce( $input['_wpnonce'], 'wp_rest' )
					&& ! headers_sent();
			}
			if ( $ready ) {
				header( 'Content-Type: application/zip' );
				header( 'Content-Disposition: attachment; filename="core-blueprint-template-resources.zip"' );
				header( 'Content-Length: ' . (string) $size );
				header( 'Cache-Control: private, no-store, max-age=0' );
				header( 'X-Content-Type-Options: nosniff' );
				header( 'Referrer-Policy: no-referrer' );
				while ( ! feof( $handle ) ) {
					$bytes = fread( $handle, 65536 );
					if ( false === $bytes || '' === $bytes ) {
						break;
					}
					echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- verified ZIP binary download.
				}
			}
		} finally {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			if ( is_string( $path ) ) {
				ResourcePackageExport::discard( $path, $root );
			}
		}
		if ( ! $ready ) {
			wp_die( 'Template package export is unavailable.', '', [ 'response' => 503 ] );
		}
		exit;
	}

	private function __construct() {}
}
