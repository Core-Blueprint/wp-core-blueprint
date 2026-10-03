<?php
declare(strict_types=1);
/**
 * Core Blueprint Forms Foundation contract definition.
 *
 * Base owns the normalized forms interoperability boundary. Concrete builders
 * and form plugins remain external providers and implement this public contract
 * through the Generic Interoperability Foundation.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Forms;

defined( 'ABSPATH' ) || exit;

final class Foundation {

	public const CONTRACT_OWNER   = 'core-blueprint';
	public const CONTRACT_ID      = 'forms.provider';
	public const CONTRACT_VERSION = '1';

	/** Provider can emit normalized form submissions into Base. */
	public const SUPPORT_SUBMISSION_EMIT = 'submission.emit';

	/**
	 * Return the immutable Base-owned interoperability contract definition.
	 *
	 * @internal Consumed by Base's private interoperability contract catalog.
	 * @return array{owner:string,id:string,version:string,label:string,description:string,interface:string}
	 */
	public static function contract_definition(): array {
		return [
			'owner'       => self::CONTRACT_OWNER,
			'id'          => self::CONTRACT_ID,
			'version'     => self::CONTRACT_VERSION,
			'label'       => __( 'Forms provider', 'core-blueprint' ),
			'description' => __( 'Provides normalized form interoperability to Core Blueprint.', 'core-blueprint' ),
			'interface'   => ProviderInterface::class,
		];
	}
}
