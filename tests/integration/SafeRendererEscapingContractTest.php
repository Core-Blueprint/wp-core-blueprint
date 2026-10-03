<?php
declare(strict_types=1);

/**
 * Regression coverage for UI renderers that are explicitly trusted as
 * escape-clean output boundaries by tools/harden-safe-renderer-output.py.
 *
 * Slot renderers such as Field and Card are intentionally excluded because
 * they accept caller-provided HTML and require per-callsite auditing.
 */
final class CB_Base_Safe_Renderer_Escaping_Contract_Test extends WP_UnitTestCase {

	private const PAYLOAD = '<script>alert("cb-xss")</script>';

	private function assert_escape_clean( string $html ): void {
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '</script>', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_notice_escapes_public_text_payloads(): void {
		$html = \CoreBlueprint\Core\UI\Notice::render( [
			'title'   => self::PAYLOAD,
			'message' => self::PAYLOAD,
			'items'   => [ self::PAYLOAD ],
			'class'   => self::PAYLOAD,
		] );

		$this->assert_escape_clean( $html );
	}

	public function test_icon_escapes_accessible_label(): void {
		$html = \CoreBlueprint\Core\UI\Icon::render( 'info', [
			'label' => self::PAYLOAD,
			'class' => self::PAYLOAD,
		] );

		$this->assert_escape_clean( $html );
	}

	public function test_state_badge_escapes_label_and_class(): void {
		$html = \CoreBlueprint\Core\UI\StateBadge::render( self::PAYLOAD, [
			'class' => self::PAYLOAD,
		] );

		$this->assert_escape_clean( $html );
	}

	public function test_choice_group_escapes_option_payloads(): void {
		$html = \CoreBlueprint\Core\UI\ChoiceGroup::render( [
			'aria_label' => self::PAYLOAD,
			'class'      => self::PAYLOAD,
			'options'    => [
				[
					'name'  => self::PAYLOAD,
					'value' => self::PAYLOAD,
					'label' => self::PAYLOAD,
					'class' => self::PAYLOAD,
				],
			],
		] );

		$this->assert_escape_clean( $html );
	}

	public function test_radio_card_escapes_public_payloads(): void {
		$html = \CoreBlueprint\Core\UI\RadioCard::render( [
			'name'  => self::PAYLOAD,
			'value' => self::PAYLOAD,
			'label' => self::PAYLOAD,
			'desc'  => self::PAYLOAD,
			'class' => self::PAYLOAD,
		] );

		$this->assert_escape_clean( $html );
	}

	public function test_radio_group_delegates_to_escape_clean_cards(): void {
		$html = \CoreBlueprint\Core\UI\RadioGroup::render( [
			'name'    => self::PAYLOAD,
			'value'   => self::PAYLOAD,
			'class'   => self::PAYLOAD,
			'options' => [
				[
					'value' => self::PAYLOAD,
					'label' => self::PAYLOAD,
					'desc'  => self::PAYLOAD,
				],
			],
		] );

		$this->assert_escape_clean( $html );
	}

	public function test_status_escapes_label(): void {
		$html = \CoreBlueprint\Core\UI\Status::render( 'active', self::PAYLOAD );
		$this->assert_escape_clean( $html );
	}

	public function test_form_status_escapes_attributes(): void {
		$html = \CoreBlueprint\Core\UI\FormStatus::render( [
			'id'     => self::PAYLOAD,
			'target' => self::PAYLOAD,
			'class'  => self::PAYLOAD,
			'data'   => [ 'data-cb-test' => self::PAYLOAD ],
		] );

		$this->assert_escape_clean( $html );
	}

	public function test_object_picker_escapes_transport_and_display_payloads(): void {
		$html = \CoreBlueprint\Core\UI\ObjectPicker::render( [
			'name'          => self::PAYLOAD,
			'id'            => self::PAYLOAD,
			'action'        => 'cb_test_action',
			'nonce'         => self::PAYLOAD,
			'context'       => [ 'payload' => self::PAYLOAD ],
			'selected'      => [
				[
					'id'    => '1',
					'label' => self::PAYLOAD,
					'meta'  => self::PAYLOAD,
				],
			],
			'placeholder'   => self::PAYLOAD,
			'empty_message' => self::PAYLOAD,
			'class'         => self::PAYLOAD,
		] );

		$this->assert_escape_clean( $html );
	}
}
