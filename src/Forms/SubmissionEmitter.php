<?php
declare(strict_types=1);
/**
 * Public normalized form-submission ingress boundary.
 *
 * Providers translate their native form runtime into this bounded transport.
 * Base validates provider admission and payload shape, then dispatches one
 * immutable in-request event. Base does not persist, queue, retry, email, audit
 * or otherwise copy the raw submitted field values.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Forms;

use CoreBlueprint\Core\Interoperability\Registry;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SubmissionEmitter {

	private const EVENT_ID_PATTERN       = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D';
	private const CONTROL_CHAR_PATTERN   = '/[\x00-\x1F\x7F]/';
	private const MAX_OPAQUE_ID_BYTES    = 191;
	private const MAX_FIELDS             = 256;
	private const MAX_LIST_ITEMS         = 100;
	private const MAX_STRING_BYTES       = 65535;
	private const MAX_TOTAL_STRING_BYTES = 1048576;

	/**
	 * Emit one normalized form submission.
	 *
	 * `$fields` is a list of `{id, value}` entries. Values may be null, scalar,
	 * or a flat list of null/scalar values. Nested maps, objects and resources
	 * are deliberately refused at the Base boundary.
	 *
	 * @param list<array{id:string,value:mixed}> $fields
	 * @return SubmissionEvent|WP_Error
	 */
	public static function emit(
		string $provider,
		string $implementation,
		string $form_id,
		array $fields,
		?string $submission_id = null,
		?string $event_id = null
	): SubmissionEvent|WP_Error {
		if ( ! Registry::is_ready() ) {
			return new WP_Error(
				'cb_core_forms_not_ready',
				'Forms Foundation is not available before the WordPress init lifecycle has completed.'
			);
		}

		$form_id = self::normalize_opaque_id( $form_id );
		if ( null === $form_id ) {
			return new WP_Error( 'cb_core_forms_invalid_form_id', 'Form id is invalid.' );
		}

		if ( null !== $submission_id ) {
			$submission_id = self::normalize_opaque_id( $submission_id );
			if ( null === $submission_id ) {
				return new WP_Error( 'cb_core_forms_invalid_submission_id', 'Submission id is invalid.' );
			}
		}

		$normalized_fields = self::normalize_fields( $fields );
		if ( null === $normalized_fields ) {
			return new WP_Error( 'cb_core_forms_invalid_fields', 'Form fields do not match the Forms Foundation transport contract.' );
		}

		if ( null === $event_id ) {
			$event_id = wp_generate_uuid4();
		} else {
			$event_id = trim( $event_id );
			if ( 1 !== preg_match( self::EVENT_ID_PATTERN, $event_id ) ) {
				return new WP_Error( 'cb_core_forms_invalid_event_id', 'Forms event id is invalid.' );
			}
		}

		$descriptor = Registry::implementation(
			Foundation::CONTRACT_OWNER,
			Foundation::CONTRACT_ID,
			Foundation::CONTRACT_VERSION,
			$provider,
			$implementation
		);
		if ( null === $descriptor ) {
			return new WP_Error( 'cb_core_forms_unknown_provider', 'Unknown Forms Foundation provider implementation.' );
		}

		if ( ! in_array( Foundation::SUPPORT_SUBMISSION_EMIT, $descriptor['supports'], true ) ) {
			return new WP_Error( 'cb_core_forms_unsupported', 'Forms provider does not support normalized submission emission.' );
		}

		$runtime = Registry::resolve(
			Foundation::CONTRACT_OWNER,
			Foundation::CONTRACT_ID,
			Foundation::CONTRACT_VERSION,
			$provider,
			$implementation
		);
		if ( $runtime instanceof WP_Error || ! $runtime instanceof ProviderInterface ) {
			return new WP_Error( 'cb_core_forms_provider_unavailable', 'Forms provider is unavailable.' );
		}

		try {
			$available = $runtime->is_available();
		} catch ( Throwable ) {
			$available = false;
		}
		if ( ! $available ) {
			return new WP_Error( 'cb_core_forms_provider_unavailable', 'Forms provider is unavailable.' );
		}

		$event = new SubmissionEvent(
			$event_id,
			(string) $descriptor['provider'],
			(string) $descriptor['id'],
			$form_id,
			$submission_id,
			$normalized_fields,
			gmdate( 'c' )
		);

		/**
		 * Fires after one Forms Foundation submission passed provider and payload validation.
		 *
		 * This is an in-request delivery boundary only. Listeners must not assume
		 * persistence or retry delivery, and should apply their own privacy policy
		 * before storing or logging submitted values.
		 *
		 * @param SubmissionEvent $event
		 */
		do_action( 'core_blueprint_forms_submission_emitted', $event );

		return $event;
	}

	private static function normalize_opaque_id( string $value ): ?string {
		$value = trim( $value );
		if (
			'' === $value
			|| strlen( $value ) > self::MAX_OPAQUE_ID_BYTES
			|| 1 === preg_match( self::CONTROL_CHAR_PATTERN, $value )
		) {
			return null;
		}
		return $value;
	}

	/**
	 * @param array<mixed> $fields
	 * @return list<array{id:string,value:mixed}>|null
	 */
	private static function normalize_fields( array $fields ): ?array {
		if ( ! array_is_list( $fields ) || count( $fields ) > self::MAX_FIELDS ) {
			return null;
		}

		$normalized = [];
		$seen_ids   = [];
		$budget     = self::MAX_TOTAL_STRING_BYTES;

		foreach ( $fields as $field ) {
			if (
				! is_array( $field )
				|| [] !== array_diff( array_keys( $field ), [ 'id', 'value' ] )
				|| ! array_key_exists( 'id', $field )
				|| ! array_key_exists( 'value', $field )
				|| ! is_string( $field['id'] )
			) {
				return null;
			}

			$id = self::normalize_opaque_id( $field['id'] );
			if ( null === $id || isset( $seen_ids[ $id ] ) ) {
				return null;
			}

			$valid = true;
			$value = self::normalize_value( $field['value'], $budget, $valid, true );
			if ( ! $valid ) {
				return null;
			}

			$seen_ids[ $id ] = true;
			$normalized[] = [
				'id'    => $id,
				'value' => $value,
			];
		}

		return $normalized;
	}

	private static function normalize_value( mixed $value, int &$budget, bool &$valid, bool $allow_list ): mixed {
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				$valid = false;
			}
			return $value;
		}

		if ( is_string( $value ) ) {
			$length = strlen( $value );
			if ( $length > self::MAX_STRING_BYTES || $length > $budget ) {
				$valid = false;
				return null;
			}
			$budget -= $length;
			return $value;
		}

		if ( $allow_list && is_array( $value ) && array_is_list( $value ) && count( $value ) <= self::MAX_LIST_ITEMS ) {
			$out = [];
			foreach ( $value as $item ) {
				$out[] = self::normalize_value( $item, $budget, $valid, false );
				if ( ! $valid ) {
					return null;
				}
			}
			return $out;
		}

		$valid = false;
		return null;
	}
}
