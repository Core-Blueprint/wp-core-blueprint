<?php
declare(strict_types=1);

use CoreBlueprint\Core\ContentModels\Importers\NativeWordPress\ValueCompatibility;

final class CB_Base_Content_Models_Native_Object_Fixture {
	public static bool $woke = false;

	public function __wakeup(): void {
		self::$woke = true;
	}
}

final class CB_Base_Content_Models_Native_WordPress_Value_Compatibility_Contract_Test extends WP_UnitTestCase {

	private int $post_id = 0;
	private string $meta_key = '_cb_native_value_compatibility_fixture';

	public function set_up(): void {
		parent::set_up();

		$this->post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );
		self::assertGreaterThan( 0, $this->post_id );
		CB_Base_Content_Models_Native_Object_Fixture::$woke = false;
	}

	public function tear_down(): void {
		global $wpdb;

		if ( $this->post_id > 0 ) {
			$wpdb->delete(
				$wpdb->postmeta,
				[
					'post_id'  => $this->post_id,
					'meta_key' => $this->meta_key,
				],
				[ '%d', '%s' ]
			);
			wp_delete_post( $this->post_id, true );
		}

		CB_Base_Content_Models_Native_Object_Fixture::$woke = false;
		parent::tear_down();
	}

	public function test_serialized_scalar_preserves_wordpress_number_compatibility(): void {
		$this->insert_raw_meta( serialize( 42 ) );

		$result = ValueCompatibility::inspect( $this->meta_definition(), 'number' );

		self::assertTrue( $result['compatible'] );
		self::assertSame( 1, $result['count'] );
	}

	public function test_serialized_array_is_decoded_as_array_instead_of_accepted_as_text(): void {
		$this->insert_raw_meta( serialize( [ 'key' => 'value' ] ) );

		$result = ValueCompatibility::inspect( $this->meta_definition(), 'text' );

		self::assertFalse( $result['compatible'] );
		self::assertSame( 1, $result['count'] );
	}

	public function test_serialized_object_is_rejected_without_instantiating_its_class(): void {
		$this->insert_raw_meta( serialize( new CB_Base_Content_Models_Native_Object_Fixture() ) );

		$result = ValueCompatibility::inspect( $this->meta_definition(), 'text' );

		self::assertFalse( $result['compatible'] );
		self::assertSame( 1, $result['count'] );
		self::assertFalse(
			CB_Base_Content_Models_Native_Object_Fixture::$woke,
			'Native WordPress meta inspection must not instantiate serialized object classes.'
		);
	}

	/** @return array<string,mixed> */
	private function meta_definition(): array {
		return [
			'object_type'    => 'post',
			'object_subtype' => 'post',
			'key'            => $this->meta_key,
			'default'        => '',
		];
	}

	private function insert_raw_meta( string $raw ): void {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->postmeta,
			[
				'post_id'    => $this->post_id,
				'meta_key'   => $this->meta_key,
				'meta_value' => $raw,
			],
			[ '%d', '%s', '%s' ]
		);

		self::assertSame( 1, $inserted );
	}
}
