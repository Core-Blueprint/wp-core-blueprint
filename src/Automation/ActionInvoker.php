<?php
declare(strict_types=1);
/**
 * Governed public invocation boundary for Automation Foundation actions.
 *
 * Base validates the registered contract and explicit WordPress execution
 * principal before entering the provider-owned executor. Workflow persistence,
 * retries and orchestration remain outside Base.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Automation;

use CoreBlueprint\Core\Automation\Internal\CapabilityRegistry;

defined( 'ABSPATH' ) || exit;

final class ActionInvoker {

	/**
	 * Invoke one registered action through the governed Base boundary.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function invoke(
		string $provider,
		string $action_id,
		string $schema_version,
		array $input,
		InvocationContext $context
	): array|\WP_Error {
		if ( ! CapabilityRegistry::is_ready() ) {
			return self::error(
				'cb_core_automation_not_ready',
				'Automation capabilities are not available before the WordPress init lifecycle has completed.'
			);
		}

		$definition = ActionRegistry::get( $provider, $action_id );
		if ( null === $definition ) {
			return self::error( 'cb_core_automation_unknown_action', 'Unknown automation action.' );
		}

		if ( $schema_version !== (string) ( $definition['schema_version'] ?? '' ) ) {
			return self::error(
				'cb_core_automation_schema_mismatch',
				'Automation action schema version does not match the registered contract.'
			);
		}

		$capability = $definition['required_capability'] ?? null;
		$principal_error = self::validate_principal( $context, $capability );
		if ( null !== $principal_error ) {
			return $principal_error;
		}

		$input_schema = $definition['input_schema'] ?? null;
		if ( ! is_array( $input_schema ) || ! Schema::validate( $input, $input_schema ) ) {
			return self::error(
				'cb_core_automation_invalid_input',
				'Automation action input does not match its registered schema.'
			);
		}

		$executor = CapabilityRegistry::executor( $provider, $action_id );
		if ( null === $executor ) {
			return self::error( 'cb_core_automation_execution_failed', 'Automation action execution failed.' );
		}

		try {
			$result = $executor( $input, $context );
		} catch ( \Throwable ) {
			return self::error( 'cb_core_automation_execution_failed', 'Automation action execution failed.' );
		}

		if ( is_wp_error( $result ) ) {
			return self::provider_error( $result, 'Automation action execution failed.' );
		}

		$output_schema = $definition['output_schema'] ?? null;
		if ( ! is_array( $result ) || ! is_array( $output_schema ) || ! Schema::validate( $result, $output_schema ) ) {
			return self::error(
				'cb_core_automation_invalid_output',
				'Automation action output does not match its registered schema.'
			);
		}

		return $result;
	}

	private static function validate_principal( InvocationContext $context, mixed $capability ): ?\WP_Error {
		$principal_user_id = $context->principal_user_id();
		if ( $principal_user_id <= 0 ) {
			return self::error( 'cb_core_automation_principal_missing', 'Automation execution principal is missing.' );
		}

		$user = get_userdata( $principal_user_id );
		if ( ! $user instanceof \WP_User ) {
			return self::error( 'cb_core_automation_principal_invalid', 'Automation execution principal is invalid.' );
		}

		if ( ! is_string( $capability ) || '' === $capability || ! user_can( $principal_user_id, $capability ) ) {
			return self::error( 'cb_core_automation_permission_denied', 'Automation execution principal is not permitted to invoke this action.' );
		}

		return null;
	}

	private static function provider_error( \WP_Error $error, string $message ): \WP_Error {
		$provider_code = sanitize_key( (string) $error->get_error_code() );
		$data = [];
		if ( '' !== $provider_code ) {
			$data['provider_error_code'] = $provider_code;
		}

		return new \WP_Error( 'cb_core_automation_execution_failed', $message, $data );
	}

	/** @param array<string,mixed> $data */
	private static function error( string $code, string $message, array $data = [] ): \WP_Error {
		return new \WP_Error( $code, $message, $data );
	}
}
