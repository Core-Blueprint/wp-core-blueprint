<?php
declare(strict_types=1);

use CoreBlueprint\Core\UI\ObjectPicker;

final class CB_Base_Object_Picker_Contract_Test extends WP_UnitTestCase {

	public function test_numeric_and_opaque_identifiers_are_preserved_as_strings(): void {
		$html = $this->render_picker(
			[
				[ 'id' => 13, 'label' => 'User 13', 'meta' => 'User' ],
				[ 'id' => 'crm:organization:42', 'label' => 'Organization 42', 'meta' => 'Organization' ],
			]
		);

		self::assertSame( '13,crm:organization:42', $this->attribute( $html, 'value' ) );
		self::assertSame(
			[
				[ 'id' => '13', 'label' => 'User 13', 'meta' => 'User' ],
				[ 'id' => 'crm:organization:42', 'label' => 'Organization 42', 'meta' => 'Organization' ],
			],
			$this->selected_state( $html )
		);
	}

	public function test_full_string_identifier_controls_deduplication(): void {
		$html = $this->render_picker(
			[
				[ 'id' => 'crm:contact:42', 'label' => 'Contact 42' ],
				[ 'id' => 'crm:organization:42', 'label' => 'Organization 42' ],
				[ 'id' => 'crm:contact:42', 'label' => 'Duplicate contact' ],
			]
		);

		self::assertSame( 'crm:contact:42,crm:organization:42', $this->attribute( $html, 'value' ) );
		self::assertSame(
			[ 'crm:contact:42', 'crm:organization:42' ],
			array_column( $this->selected_state( $html ), 'id' )
		);
	}

	public function test_single_selection_keeps_only_first_valid_identifier(): void {
		$html = $this->render_picker(
			[
				[ 'id' => '   ', 'label' => 'Invalid' ],
				[ 'id' => 'crm:contact:42', 'label' => 'Contact 42' ],
				[ 'id' => 'crm:organization:42', 'label' => 'Organization 42' ],
			],
			false
		);

		self::assertSame( 'crm:contact:42', $this->attribute( $html, 'value' ) );
		self::assertSame( [ 'crm:contact:42' ], array_column( $this->selected_state( $html ), 'id' ) );
	}


	public function test_generic_helper_hint_can_be_suppressed_without_changing_selection_transport(): void {
		$html = ObjectPicker::render( [
			'id'        => 'object-picker-hint-contract',
			'name'      => 'object_picker_hint_contract',
			'multiple'  => false,
			'action'    => 'cb_test_object_picker_search',
			'nonce'     => 'object-picker-contract-nonce',
			'show_hint' => false,
			'selected'  => [
				[ 'id' => 'page:13', 'label' => 'Disclaimer', 'meta' => 'Published page' ],
			],
		] );

		self::assertSame( 'page:13', $this->attribute( $html, 'value' ) );
		self::assertStringNotContainsString( 'Search and select one item.', $html );
		self::assertStringContainsString( 'data-multiple="0"', $html );
	}

	public function test_invalid_identifiers_are_rejected_safely(): void {
		$html = $this->render_picker(
			[
				[ 'id' => '', 'label' => 'Empty' ],
				[ 'id' => false, 'label' => 'False' ],
				[ 'id' => [ 'nested' ], 'label' => 'Array' ],
				[ 'id' => 'contains,comma', 'label' => 'Comma' ],
				[ 'id' => str_repeat( 'a', 192 ), 'label' => 'Too long' ],
				[ 'id' => ' valid:token:7 ', 'label' => 'Valid' ],
			]
		);

		self::assertSame( 'valid:token:7', $this->attribute( $html, 'value' ) );
		self::assertSame( [ 'valid:token:7' ], array_column( $this->selected_state( $html ), 'id' ) );
	}

	/** @param array<int,mixed> $selected */
	private function render_picker( array $selected, bool $multiple = true ): string {
		return ObjectPicker::render( [
			'id'       => 'object-picker-contract',
			'name'     => 'object_picker_contract',
			'multiple' => $multiple,
			'action'   => 'cb_test_object_picker_search',
			'nonce'    => 'object-picker-contract-nonce',
			'selected' => $selected,
		] );
	}

	/** @return array<int,array{id:string,label:string,meta:string}> */
	private function selected_state( string $html ): array {
		$decoded = json_decode( $this->attribute( $html, 'data-selected' ), true );
		self::assertIsArray( $decoded );
		return $decoded;
	}

	private function attribute( string $html, string $name ): string {
		$pattern = '/(?:^|\\s)' . preg_quote( $name, '/' ) . '="([^"]*)"/';
		self::assertSame( 1, preg_match( $pattern, $html, $matches ), 'Missing attribute: ' . $name );
		return html_entity_decode( (string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
