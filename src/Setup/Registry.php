<?php
declare(strict_types=1);
/**
 * Internal Core Setup v1 check registry.
 *
 * V1 is intentionally Base-owned. There is no extension filter here: an
 * extension-contributed setup contract is explicitly post-v1 scope.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup;

use CoreBlueprint\Core\Setup\Checks\AccessModeCheck;
use CoreBlueprint\Core\Setup\Checks\AdminColumnsCheck;
use CoreBlueprint\Core\Setup\Checks\AdminNavigationCheck;
use CoreBlueprint\Core\Setup\Checks\AdminNoticesCheck;
use CoreBlueprint\Core\Setup\Checks\RoutingUrlsCheck;
use CoreBlueprint\Core\Setup\Checks\AuditRetentionCheck;
use CoreBlueprint\Core\Setup\Checks\AuditVerbosityCheck;
use CoreBlueprint\Core\Setup\Checks\ContentModelsCheck;
use CoreBlueprint\Core\Setup\Checks\CoreScannerPolicyCheck;
use CoreBlueprint\Core\Setup\Checks\CoreScannerReadinessCheck;
use CoreBlueprint\Core\Setup\Checks\CoreShieldCheck;
use CoreBlueprint\Core\Setup\Checks\EnvironmentIdentityCheck;
use CoreBlueprint\Core\Setup\Checks\EnvironmentIndexingProtectionCheck;
use CoreBlueprint\Core\Setup\Checks\FailsafeReadinessCheck;
use CoreBlueprint\Core\Setup\Checks\LoginShieldCheck;
use CoreBlueprint\Core\Setup\Checks\MailDeliveryReadinessCheck;
use CoreBlueprint\Core\Setup\Checks\MailDeliveryStrategyCheck;
use CoreBlueprint\Core\Setup\Checks\MailDesignerCheck;
use CoreBlueprint\Core\Setup\Checks\MediaFormatsCheck;
use CoreBlueprint\Core\Setup\Checks\ModuleActivationDecisionCheck;
use CoreBlueprint\Core\Setup\Checks\NotificationsPolicyCheck;
use CoreBlueprint\Core\Setup\Checks\OperationalLogsCheck;
use CoreBlueprint\Core\Setup\Checks\OperationalToolsCheck;
use CoreBlueprint\Core\Setup\Checks\PrivacyIpHandlingCheck;
use CoreBlueprint\Core\Setup\Checks\PrivilegedAccessProtectionCheck;
use CoreBlueprint\Core\Setup\Checks\PrivilegedAccessReviewCheck;
use CoreBlueprint\Core\Setup\Checks\SnippetsCheck;
use CoreBlueprint\Core\Setup\Checks\TwoFactorReadinessCheck;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** @return array<string,CheckInterface> */
	public static function all(): array {
		$checks = [
			new EnvironmentIdentityCheck(),
			new EnvironmentIndexingProtectionCheck(),
			new AccessModeCheck(),

			new PrivilegedAccessProtectionCheck(),
			new PrivilegedAccessReviewCheck(),
			new TwoFactorReadinessCheck(),
			new FailsafeReadinessCheck(),

			new CoreShieldCheck(),
			new LoginShieldCheck(),
			new CoreScannerPolicyCheck(),
			new CoreScannerReadinessCheck(),

			new OperationalLogsCheck(),
			new NotificationsPolicyCheck(),
			new OperationalToolsCheck(),

			new MailDeliveryStrategyCheck(),
			new MailDeliveryReadinessCheck(),
			new MailDesignerCheck(),

			new PrivacyIpHandlingCheck(),
			new AuditRetentionCheck(),
			new AuditVerbosityCheck(),

			new ContentModelsCheck(),
			new SnippetsCheck(),
			new ModuleActivationDecisionCheck(
				'user-roles',
				'User Roles',
				admin_url( 'admin.php?page=core-blueprint-user-roles' )
			),
			new ModuleActivationDecisionCheck(
				'media-replace',
				'Media Replace',
				admin_url( 'admin.php?page=core-blueprint-media-replace' )
			),
			new MediaFormatsCheck(),
			new ModuleActivationDecisionCheck(
				'package-downloads',
				'Package Downloads',
				admin_url( 'admin.php?page=core-blueprint-package-downloads' )
			),
			new AdminNavigationCheck(),
			new AdminColumnsCheck(),
			new AdminNoticesCheck(),
			new RoutingUrlsCheck(),
		];

		$out = [];
		foreach ( $checks as $check ) {
			self::assert_valid( $check );
			if ( isset( $out[ $check->id() ] ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \LogicException( 'Duplicate Core Setup check ID: ' . $check->id() );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$out[ $check->id() ] = $check;
		}

		return $out;
	}

	public static function get( string $id ): ?CheckInterface {
		return self::all()[ $id ] ?? null;
	}

	/** @return array<string,CheckInterface> */
	public static function visible(): array {
		$out = [];
		foreach ( self::all() as $id => $check ) {
			if ( current_user_can( $check->capability() ) ) {
				$out[ $id ] = $check;
			}
		}
		return $out;
	}

	/** @param array<string,CheckInterface>|null $checks @return array<string,array<string,CheckInterface>> */
	public static function sections( ?array $checks = null ): array {
		$checks ??= self::all();
		$sections = [];
		foreach ( $checks as $id => $check ) {
			$sections[ $check->section() ][ $id ] = $check;
		}
		return $sections;
	}

	private static function assert_valid( CheckInterface $check ): void {
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $check->id() ) ) {
			throw new \LogicException( 'Invalid Core Setup check ID.' );
		}
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $check->section() ) ) {
			throw new \LogicException( 'Invalid Core Setup section ID.' );
		}
		if ( ! in_array( $check->kind(), CheckInterface::KINDS, true ) ) {
			throw new \LogicException( 'Invalid Core Setup check kind.' );
		}
		if ( '' === $check->label() || '' === sanitize_key( $check->capability() ) ) {
			throw new \LogicException( 'Incomplete Core Setup check definition.' );
		}
	}

	private function __construct() {}
}
