<?php
declare(strict_types=1);
/**
 * Failsafe\RotateToken - `wp cb failsafe rotate-token` (destructive).
 *
 * Rotates the secret bypass URL token. The new token is returned exactly
 * once - there is no recovery if the operator doesn't capture it. The
 * Console renders this through a special "secret token" modal with
 * copy-to-clipboard and an explicit acknowledgment before the token is
 * shown elsewhere.
 *
 * Result data carries a `sensitive_output` flag the runner reads to
 * trigger the secret-modal flow instead of the standard output panel.
 * The bypass URL is in `data.bypass_url`; a placeholder appears in the
 * lines so the line-by-line view doesn't accidentally leak it.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands\Failsafe;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;

defined( 'ABSPATH' ) || exit;

final class RotateToken implements CommandInterface {

	public function execute( array $args ): Result {
		$token = \CoreBlueprint\Core\Security\Failsafe::rotate_token();
		$url   = \CoreBlueprint\Core\Security\Failsafe::build_bypass_url( $token );

		// Lines do NOT contain the URL - the Console reads the URL from
		// data.bypass_url and renders it through a copy-to-clipboard modal
		// that auto-hides on close. Putting the URL into lines too would
		// expose it in the always-visible output panel.
		$lines = [
			'New bypass token generated.',
			'',
			'The bypass URL is shown ONCE in the secret-token dialog.',
			'It is not echoed to the output panel for safety.',
			'',
			'Using this URL will:',
			'  - Disable restrictive features for 60 minutes',
			'  - Rotate the token (single-use)',
			'  - Send an email notification to ' . get_option( 'admin_email' ),
		];

		return Result::success(
			__( 'New bypass token generated. Copy the URL from the dialog - it will not be shown again.', 'core-blueprint' ),
			$lines,
			[
				'sensitive_output' => true,
				'bypass_url'       => $url,
				'admin_email'      => (string) get_option( 'admin_email' ),
			]
		);
	}

	public function args_schema(): array {
		return [];
	}

	public function side_effects(): string {
		return 'destructive';
	}

	/**
	 * Rotate the secret bypass URL token. The new token is printed once.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb failsafe rotate-token
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$token = \CoreBlueprint\Core\Security\Failsafe::rotate_token();
		$url   = \CoreBlueprint\Core\Security\Failsafe::build_bypass_url( $token );

		\WP_CLI::success( 'New bypass token generated.' );
		\WP_CLI::line( '' );
		\WP_CLI::line( 'Bypass URL (SAVE THIS - it will not be shown again):' );
		\WP_CLI::line( '' );
		\WP_CLI::line( '  ' . $url );
		\WP_CLI::line( '' );
		\WP_CLI::line( 'Store this URL in your password manager. Using it will:' );
		\WP_CLI::line( '  - Disable restrictive features for 60 minutes' );
		\WP_CLI::line( '  - Rotate the token (single-use)' );
		\WP_CLI::line( '  - Send an email notification to ' . get_option( 'admin_email' ) );
		\WP_CLI::line( '' );
	}
}
