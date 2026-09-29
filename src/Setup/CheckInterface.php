<?php
declare(strict_types=1);
/**
 * Core Setup check contract.
 *
 * Checks are read-only evidence providers. They never own or mutate the
 * configuration they inspect.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup;
defined( 'ABSPATH' ) || exit;

interface CheckInterface {

	public const KIND_REQUIRED      = 'required';
	public const KIND_DECISION      = 'decision';
	public const KIND_OPTIONAL      = 'optional';
	public const KIND_INFORMATIONAL = 'informational';
	public const KINDS = [ self::KIND_REQUIRED, self::KIND_DECISION, self::KIND_OPTIONAL, self::KIND_INFORMATIONAL ];

	public function id(): string;

	public function section(): string;

	/** Translation-free internal fallback; presentation owns localized copy. */
	public function label(): string;

	public function kind(): string;

	public function capability(): string;

	public function evidence(): Evidence;

	public function configuration_url(): string;

	public function allows_later(): bool;

	public function allows_not_applicable( Evidence $evidence ): bool;
}
