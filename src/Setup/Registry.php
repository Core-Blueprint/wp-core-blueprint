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

namespace CB\Core\Setup;

use CB\Core\Setup\Checks\AccessModeCheck;
use CB\Core\Setup\Checks\AdminColumnsCheck;
use CB\Core\Setup\Checks\AdminNavigationCheck;
use CB\Core\Setup\Checks\AuditRetentionCheck;
use CB\Core\Setup\Checks\AuditVerbosityCheck;
use CB\Core\Setup\Checks\ContentModelsCheck;
use CB\Core\Setup\Checks\CoreScannerPolicyCheck;
use CB\Core\Setup\Checks\CoreScannerReadinessCheck;
use CB\Core\Setup\Checks\CoreShieldCheck;
use CB\Core\Setup\Checks\EnvironmentIdentityCheck;
use CB\Core\Setup\Checks\EnvironmentIndexingProtectionCheck;
use CB\Core\Setup\Checks\FailsafeReadinessCheck;
use CB\Core\Setup\Checks\LoginShieldCheck;
use CB\Core\Setup\Checks\MailDeliveryReadinessCheck;
use CB\Core\Setup\Checks\MailDeliveryStrategyCheck;
use CB\Core\Setup\Checks\MailDesignerCheck;
use CB\Core\Setup\Checks\MediaFormatsCheck;
use CB\Core\Setup\Checks\ModuleActivationDecisionCheck;
use CB\Core\Setup\Checks\NotificationsPolicyCheck;
use CB\Core\Setup\Checks\OperationalLogsCheck;
use CB\Core\Setup\Checks\OperationalToolsCheck;
use CB\Core\Setup\Checks\PrivacyIpHandlingCheck;
use CB\Core\Setup\Checks\PrivilegedAccessProtectionCheck;
use CB\Core\Setup\Checks\PrivilegedAccessReviewCheck;
use CB\Core\Setup\Checks\RoutingUrlsCheck;
use CB\Core\Setup\Checks\SnippetsCheck;
use CB\Core\Setup\Checks\TwoFactorReadinessCheck;

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
			new RoutingUrlsCheck(),
		];

		$out = [];
		foreach ( $checks as $check ) {
			self::assert_valid( $check );
			if ( isset( $out[ $check->id() ] ) ) {
				throw new \LogicException( 'Duplicate Core Setup check ID: ' . $check->id() );
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
