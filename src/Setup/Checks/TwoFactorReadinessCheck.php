<?php
declare(strict_types=1);
/**
 * Core Setup evidence for privileged-user two-factor readiness.
 *
 * Base enrollment and supported external-provider ownership are both valid.
 * Authentication material and recovery-code hashes never enter Setup evidence.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\Admin\Pages\Safeguards;
use CB\Core\Permissions\PrivilegedAccessPolicy;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\Policy;
use CB\Core\Security\TwoFactor\ProviderDetector;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;
use CB\Core\Setup\Fingerprint;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class TwoFactorReadinessCheck implements CheckInterface {

	public function id(): string { return 'two-factor-readiness'; }
	public function section(): string { return 'administrator-recovery'; }
	public function label(): string { return 'Two-factor readiness'; }
	public function kind(): string { return self::KIND_DECISION; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Safeguards::SLUG . '&tab=two-factor' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$config = Policy::config();
			$identity_hashes = [];
			$total = 0;
			$protected = 0;
			$unprotected = 0;
			$base_enrolled = 0;
			$external = 0;
			$base_without_recovery = 0;

			foreach ( get_users() as $user ) {
				if ( ! $user instanceof WP_User || ! PrivilegedAccessPolicy::is_privileged( $user ) ) {
					continue;
				}

				$total++;
				$user_id   = (int) $user->ID;
				$base      = CredentialStore::is_enrolled( $user_id );
				$providers = ProviderDetector::providers_for_user( $user );
				sort( $providers, SORT_STRING );
				$external_owned = [] !== $providers;
				$recovery_present = $base && [] !== CredentialStore::recovery_hashes( $user_id );
				$is_protected = $base || $external_owned;

				$protected += $is_protected ? 1 : 0;
				$unprotected += $is_protected ? 0 : 1;
				$base_enrolled += $base ? 1 : 0;
				$external += $external_owned ? 1 : 0;
				$base_without_recovery += $base && ! $recovery_present ? 1 : 0;

				$identity_hashes[] = Fingerprint::hash( [
					'user_id'          => $user_id,
					'base_enrolled'    => $base,
					'external'         => $providers,
					'recovery_present' => $recovery_present,
				] );
			}
			sort( $identity_hashes, SORT_STRING );

			$attention = Policy::MODE_ENFORCE === $config['mode'] && $unprotected > 0;
			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention ? 'two-factor.enforcement-gap' : 'two-factor.ready',
				[
					'policy'                    => $config,
					'privileged_identity_hashes'=> $identity_hashes,
					'total'                     => $total,
					'protected'                 => $protected,
					'unprotected'               => $unprotected,
					'base_enrolled'             => $base_enrolled,
					'external_provider_owned'   => $external,
					'base_without_recovery'     => $base_without_recovery,
				],
				[
					'policy_mode'               => $config['mode'],
					'total'                     => $total,
					'protected'                 => $protected,
					'unprotected'               => $unprotected,
					'base_enrolled'             => $base_enrolled,
					'external_provider_owned'   => $external,
					'base_without_recovery'     => $base_without_recovery,
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'two-factor.unavailable' );
		}
	}
}
