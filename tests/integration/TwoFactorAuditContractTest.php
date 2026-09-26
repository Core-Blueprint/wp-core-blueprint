<?php
declare(strict_types=1);

use CB\Core\Governance\EventRegistry;
use CB\Core\Security\TwoFactor\Audit;
use CB\Core\Security\TwoFactor\Bootstrap;

final class CB_Base_Two_Factor_Audit_Contract_Test extends WP_UnitTestCase {

	public function test_ta1_all_current_two_factor_events_have_canonical_labels(): void {
		$labels = Bootstrap::register_event_labels( [] );

		self::assertSame(
			[
				Audit::EVENT_ENROLLMENT_STARTED,
				Audit::EVENT_ENROLLMENT_COMPLETED,
				Audit::EVENT_RECOVERY_CODE_USED,
				Audit::EVENT_AUTHENTICATED,
				Audit::EVENT_BYPASS_USED,
				Audit::EVENT_MIGRATION_RESET,
				Audit::EVENT_POLICY_CHANGED,
				Audit::EVENT_AUTHENTICATION_RESET,
				Audit::EVENT_REMOVED,
			],
			array_keys( $labels )
		);

		foreach ( $labels as $id => $label ) {
			self::assertNotSame( '', $label, $id );
			self::assertTrue( EventRegistry::is_valid_id( $id ), $id );
		}
	}

	public function test_ta2_security_event_storage_keys_fit_the_governance_boundary(): void {
		foreach ( [
			Audit::EVENT_ENROLLMENT_STARTED,
			Audit::EVENT_ENROLLMENT_COMPLETED,
			Audit::EVENT_RECOVERY_CODE_USED,
			Audit::EVENT_AUTHENTICATED,
			Audit::EVENT_BYPASS_USED,
			Audit::EVENT_MIGRATION_RESET,
			Audit::EVENT_POLICY_CHANGED,
			Audit::EVENT_AUTHENTICATION_RESET,
			Audit::EVENT_REMOVED,
		] as $id ) {
			$key = EventRegistry::storage_key( $id );
			self::assertIsString( $key );
			self::assertLessThanOrEqual( 50, strlen( $key ) );
		}
	}
}
