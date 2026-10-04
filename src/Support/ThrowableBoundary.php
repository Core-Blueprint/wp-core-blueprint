<?php
declare(strict_types=1);

/**
 * Safe projection of unexpected Throwable metadata across persistent,
 * operator-facing and extension/provider boundaries.
 *
 * Raw exception messages are deliberately excluded. Throwable messages may
 * contain credentials, tokens, filesystem paths or arbitrary provider data.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 * @internal
 */

namespace CoreBlueprint\Core\Support;

use Throwable;

defined( 'ABSPATH' ) || exit;

final class ThrowableBoundary {

	/**
	 * Return bounded, non-message metadata suitable for audit context.
	 *
	 * @return array{reason:string,exception_class:string}
	 */
	public static function context( Throwable $throwable, string $reason ): array {
		return [
			'reason'          => sanitize_key( $reason ),
			'exception_class' => get_class( $throwable ),
		];
	}

	/**
	 * Return a non-secret diagnostic identity for last-resort debug logging.
	 */
	public static function diagnostic( Throwable $throwable ): string {
		return get_class( $throwable );
	}
}
