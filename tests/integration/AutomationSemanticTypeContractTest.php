<?php
declare(strict_types=1);

use CoreBlueprint\Core\Automation\Schema;
use CoreBlueprint\Core\Automation\TriggerRegistry;

final class CB_Base_Automation_Semantic_Type_Contract_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		TriggerRegistry::_reset_for_testing();
	}

	public function tear_down(): void {
		TriggerRegistry::_reset_for_testing();
		parent::tear_down();
	}

	public function test_semantic_type_is_optional_and_legacy_schema_shape_is_unchanged(): void {
		$normalized = Schema::normalize( [
			'value' => [ 'type' => 'integer', 'required' => true ],
		] );

		self::assertSame( [
			'value' => [
				'type'      => 'integer',
				'required'  => true,
				'sensitive' => false,
				'items'     => null,
			],
		], $normalized );
		self::assertArrayNotHasKey( 'semantic_type', $normalized['value'] );
	}

	public function test_valid_semantic_types_are_normalized_preserved_and_idempotent(): void {
		$schema = [
			'user_id' => [
				'type'          => 'integer',
				'required'      => true,
				'semantic_type' => ' wp.user_id ',
			],
			'course_id' => [
				'type'          => 'integer',
				'semantic_type' => 'core-blueprint-lms.course_id',
			],
			'profile_id' => [
				'type'          => 'integer',
				'semantic_type' => 'core-blueprint-certificates.profile_id',
			],
		];

		$normalized = Schema::normalize( $schema );

		self::assertIsArray( $normalized );
		self::assertSame( 'wp.user_id', $normalized['user_id']['semantic_type'] ?? null );
		self::assertSame( 'core-blueprint-lms.course_id', $normalized['course_id']['semantic_type'] ?? null );
		self::assertSame( 'core-blueprint-certificates.profile_id', $normalized['profile_id']['semantic_type'] ?? null );
		self::assertSame( $normalized, Schema::normalize( $normalized ) );
	}

	public function test_invalid_semantic_types_fail_closed(): void {
		$invalid = [
			'',
			'user_id',
			'WP.user_id',
			'wp.User_id',
			'wp.user id',
			'wp..user_id',
			str_repeat( 'a', 121 ) . '.id',
			42,
			null,
		];

		foreach ( $invalid as $semantic_type ) {
			self::assertNull(
				Schema::normalize( [
					'value' => [
						'type'          => 'integer',
						'semantic_type' => $semantic_type,
					],
				] ),
				'Invalid semantic type should fail closed: ' . var_export( $semantic_type, true )
			);
		}
	}

	public function test_semantic_metadata_does_not_change_runtime_transport_validation(): void {
		$schema = [
			'user_id' => [
				'type'          => 'integer',
				'required'      => true,
				'semantic_type' => 'wp.user_id',
			],
		];

		self::assertTrue( Schema::validate( [ 'user_id' => 42 ], $schema ) );
		self::assertFalse( Schema::validate( [ 'user_id' => '42' ], $schema ) );
	}

	public function test_semantic_type_is_visible_through_capability_discovery(): void {
		self::assertTrue( TriggerRegistry::register_base( [
			'provider'       => 'ignored-for-base-owned-trigger',
			'id'             => 'user.changed',
			'label'          => 'User changed',
			'description'    => 'Semantic discovery fixture.',
			'schema_version' => '1',
			'payload_schema' => [
				'user_id' => [
					'type'          => 'integer',
					'required'      => true,
					'semantic_type' => 'wp.user_id',
				],
			],
		] ) );

		$definition = TriggerRegistry::get( 'core-blueprint', 'user.changed' );

		self::assertIsArray( $definition );
		self::assertSame( 'wp.user_id', $definition['payload_schema']['user_id']['semantic_type'] ?? null );
	}
}
