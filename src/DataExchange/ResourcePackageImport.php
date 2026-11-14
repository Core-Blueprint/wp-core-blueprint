<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\DataExchange;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Internal scoped bridge from a verified private stage into the ONE Data Exchange Engine.
 * Consumer plan_import MUST call bind_plan(); apply_import MUST call assert_bound_plan().
 * No endpoint, archive parser, media persistence or second import engine.
 */
final class ResourcePackageImport {
	private const SCOPE_KEY = 'cb_resource_package_scope';
	private const PLAN_KEY = 'cb_resource_package_binding';
	private static ?array $active = null;

	/** @return array<string,mixed>|WP_Error */
	public static function preview( string $ticket, string $private_root, string $destination, array $consumer_context = [] ): array|WP_Error {
		return self::execute( $ticket, $private_root, $destination, null, $consumer_context );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function apply( string $ticket, string $private_root, string $destination, string $fingerprint, array $consumer_context = [] ): array|WP_Error {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
			return new WP_Error( 'cb_core_data_exchange_invalid_fingerprint', 'Data Exchange import fingerprint is invalid.' );
		}
		return self::execute( $ticket, $private_root, $destination, $fingerprint, $consumer_context );
	}

	/**
	 * Consumer calls this as the LAST step in plan_import() for a package record.
	 * Adds verified evidence to the existing opaque plan payload, which Engine fingerprints.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function bind_plan( array $plan, array $context ): array|WP_Error {
		$binding = self::binding( $context );
		if ( is_wp_error( $binding ) || ! is_array( $plan['payload'] ?? null )
			|| array_key_exists( self::PLAN_KEY, $plan['payload'] )
			|| Foundation::OP_CREATE !== ( $plan['operation'] ?? null ) ) {
			return self::refuse();
		}
		$plan['payload'][ self::PLAN_KEY ] = $binding;
		++self::$active['bind_count'];
		return $plan;
	}

	/**
	 * Consumer MUST call immediately before its own canonical create operation.
	 * This is not a general permission grant: consumer ACL/destination checks still apply.
	 */
	public static function assert_bound_plan( array $plan, array $context ): bool {
		$binding = self::binding( $context );
		return ! is_wp_error( $binding )
			&& is_array( $plan['payload'] ?? null )
			&& ( $plan['payload'][ self::PLAN_KEY ] ?? null ) === $binding;
	}

	/**
	 * Give the owning consumer the exact resource bytes only during the
	 * canonical Engine apply_import callback and only for its currently bound
	 * verified plan. Replanning is not a resource-reading authority.
	 * This is read-only: consumer-specific media validation, ownership,
	 * journaling, rollback and persistence are never performed by Base.
	 *
	 * @return string|WP_Error
	 */
	public static function read_resource( array $plan, array $context, string $key ): string|WP_Error {
		if ( null === self::$active || 'apply' !== ( self::$active['phase'] ?? null )
			|| ! Engine::in_apply_callback()
			|| ! self::assert_bound_plan( $plan, $context )
			|| 1 !== preg_match( '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $key )
			|| strlen( $key ) > 96 ) {
			return self::refuse();
		}
		$found = false;
		foreach ( self::$active['binding']['resources'] as $resource ) {
			if ( ( $resource['key'] ?? null ) === $key ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return self::refuse();
		}
		$bytes = ResourcePackageStage::read_resource(
			self::$active['ticket'],
			self::$active['private_root'],
			self::$active['binding']['destination_id'],
			$key
		);
		// Re-read authorization and digest inside Stage at every call;
		// a prior preview/plan never authorizes a later byte read.
		return is_wp_error( $bytes ) ? self::refuse() : $bytes;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function execute( string $ticket, string $private_root, string $destination, ?string $fingerprint, array $consumer_context ): array|WP_Error {
		if ( null !== self::$active || '' === $destination || ! self::valid_context( $consumer_context ) ) {
			return self::refuse();
		}
		$verified = ResourcePackageStage::inspect( $ticket, $private_root, $destination );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		if ( ! self::valid_verified( $verified ) ) {
			return self::refuse();
		}
		try {
			$scope = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return self::refuse();
		}
		// Only the current in-process verified stage creates this scoped context.
		$binding = [
			'actor'           => get_current_user_id(),
			'destination_id'  => $destination,
			'package_sha256'  => $verified['package_sha256'],
			'document_sha256' => $verified['document_sha256'],
			'extension_id'    => $verified['extension_id'],
			'entity'          => $verified['entity'],
			'resources'       => $verified['resources'],
		];
		$context = $consumer_context;
		$context['intent'] = 'create_personal_copy';
		$context['destination_id'] = $destination;
		$context[ self::SCOPE_KEY ] = $scope;
		self::$active = [
			'scope' => $scope, 'binding' => $binding, 'bind_count' => 0,
			'phase' => 'preview', 'ticket' => $ticket, 'private_root' => $private_root,
		];
		try {
			$preview = Engine::preview_json( $verified['document_json'], Foundation::MODE_CREATE_ONLY, $context );
			if ( is_wp_error( $preview ) || ! is_array( $preview ) ) {
				return $preview;
			}
			if ( ! ( $preview['valid'] ?? false ) ) {
				return null === $fingerprint
					? $preview
					: new WP_Error( 'cb_core_data_exchange_invalid_records', 'Data Exchange import contains invalid records.', [ 'preview' => $preview ] );
			}
			if ( 1 !== self::$active['bind_count'] || 1 !== ( $preview['record_count'] ?? null ) ) {
				return self::refuse();
			}
			if ( null === $fingerprint ) {
				return $preview;
			}
			if ( ! hash_equals( $preview['fingerprint'], $fingerprint ) ) {
				return new WP_Error( 'cb_core_data_exchange_stale_plan', 'Data Exchange import plan changed after preview.' );
			}
			// Engine re-plans and checks its own canonical fingerprint BEFORE apply_import().
			// Omitting bind_plan during that re-plan changes the fingerprint and fails closed.
			self::$active['phase'] = 'apply';
			return Engine::apply_json( $verified['document_json'], Foundation::MODE_CREATE_ONLY, $fingerprint, $context );
		} finally {
			self::$active = null; // Never leak authorization into another import request.
		}
	}

	/** @return array<string,mixed>|WP_Error */
	private static function binding( array $context ): array|WP_Error {
		if ( null === self::$active || ! is_string( $context[ self::SCOPE_KEY ] ?? null )
			|| ! hash_equals( self::$active['scope'], $context[ self::SCOPE_KEY ] )
			|| self::$active['binding']['actor'] !== get_current_user_id()
			|| self::$active['binding']['destination_id'] !== ( $context['destination_id'] ?? null )
			|| 'create_personal_copy' !== ( $context['intent'] ?? null ) ) {
			return self::refuse();
		}
		return self::$active['binding'];
	}

	private static function valid_context( array $context ): bool {
		return ! array_key_exists( self::SCOPE_KEY, $context )
			&& ! array_key_exists( self::PLAN_KEY, $context )
			&& ! array_key_exists( 'intent', $context )
			&& ! array_key_exists( 'destination_id', $context );
	}

	private static function valid_verified( array $verified ): bool {
		return is_string( $verified['document_json'] ?? null )
			&& strlen( $verified['document_json'] ) <= Foundation::MAX_INPUT_BYTES
			&& is_string( $verified['package_sha256'] ?? null )
			&& is_string( $verified['document_sha256'] ?? null )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $verified['package_sha256'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $verified['document_sha256'] )
			&& hash( 'sha256', $verified['document_json'] ) === $verified['document_sha256']
			&& is_string( $verified['extension_id'] ?? null )
			&& is_string( $verified['entity'] ?? null )
			&& is_array( $verified['resources'] ?? null )
			&& array_is_list( $verified['resources'] )
			&& count( $verified['resources'] ) <= 16;
	}

	private static function refuse(): WP_Error {
		return new WP_Error( 'cb_core_resource_package_binding_invalid', 'Verified resource package import binding is unavailable.' );
	}
}
