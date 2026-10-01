<?php
declare(strict_types=1);
/**
 * Public declarative component registry for the Base Mail Designer.
 *
 * Extensions can add domain-specific blocks (for example WooCommerce order
 * items) without shipping a second editor. Inspector fields are declarative;
 * domain rendering remains owned by the registering extension.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Mail\Designer;

defined( 'ABSPATH' ) || exit;

final class ComponentRegistry {
	/** @var array<string,array<string,mixed>> */
	private static array $definitions = [];
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		self::register_core();
		add_filter( 'cb_core_design_mail_node_types', [ __CLASS__, 'contribute_node_types' ] );
		/** Fires once so extensions can add blocks to the shared Mail Designer. */
		do_action( 'cb_core_register_mail_components' );
	}

	/**
	 * @param array{id:string,provider:string,label:string,node_type:string,defaults:array<string,mixed>,inspector?:list<array<string,mixed>>} $definition
	 */
	public static function register( array $definition ): bool {
		$id = isset( $definition['id'] ) ? sanitize_key( (string) $definition['id'] ) : '';
		$provider = isset( $definition['provider'] ) ? sanitize_key( (string) $definition['provider'] ) : '';
		$label = isset( $definition['label'] ) ? sanitize_text_field( (string) $definition['label'] ) : '';
		$node_type = isset( $definition['node_type'] ) ? trim( (string) $definition['node_type'] ) : '';
		$defaults = $definition['defaults'] ?? null;
		$inspector = $definition['inspector'] ?? [];

		if (
			'' === $id || '' === $provider || '' === $label
			|| 1 !== preg_match( '/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/', $node_type )
			|| ! is_array( $defaults ) || ( [] !== $defaults && array_is_list( $defaults ) )
			|| ! is_array( $inspector ) || isset( self::$definitions[ $id ] )
		) {
			return false;
		}

		$normalized_fields = [];
		foreach ( $inspector as $field ) {
			$normalized = self::normalize_field( $field );
			if ( null === $normalized ) {
				return false;
			}
			$normalized_fields[] = $normalized;
		}

		self::$definitions[ $id ] = [
			'id'        => $id,
			'provider'  => $provider,
			'label'     => $label,
			'node_type' => $node_type,
			'defaults'  => $defaults,
			'inspector' => $normalized_fields,
		];
		return true;
	}

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		self::boot();
		return self::$definitions;
	}

	/** @param array<string,string> $types @return array<string,string> */
	public static function contribute_node_types( array $types ): array {
		foreach ( self::$definitions as $definition ) {
			$types[ (string) $definition['node_type'] ] = (string) $definition['provider'];
		}
		return $types;
	}

	/** @param array<string,mixed> $field @return array<string,mixed>|null */
	private static function normalize_field( mixed $field ): ?array {
		if ( ! is_array( $field ) ) {
			return null;
		}
		$key = isset( $field['key'] ) ? sanitize_key( (string) $field['key'] ) : '';
		$label = isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '';
		$type = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text';
		if ( '' === $key || '' === $label || ! in_array( $type, [ 'text', 'textarea', 'url', 'number', 'color', 'select' ], true ) ) {
			return null;
		}

		$out = [ 'key' => $key, 'label' => $label, 'type' => $type ];
		if ( 'number' === $type ) {
			$out['min'] = isset( $field['min'] ) ? (float) $field['min'] : 0;
			$out['max'] = isset( $field['max'] ) ? (float) $field['max'] : 1000;
			$out['step'] = isset( $field['step'] ) ? max( 0.01, (float) $field['step'] ) : 1;
		}
		if ( 'select' === $type ) {
			$options = is_array( $field['options'] ?? null ) ? $field['options'] : [];
			$out['options'] = [];
			foreach ( $options as $value => $option_label ) {
				$value = sanitize_key( (string) $value );
				if ( '' !== $value && is_scalar( $option_label ) ) {
					$out['options'][ $value ] = sanitize_text_field( (string) $option_label );
				}
			}
		}
		return $out;
	}

	private static function register_core(): void {
		$align = [ 'left' => __( 'Left', 'core-blueprint' ), 'center' => __( 'Center', 'core-blueprint' ), 'right' => __( 'Right', 'core-blueprint' ) ];
		$definitions = [
			[ 'id' => 'heading', 'label' => __( 'Heading', 'core-blueprint' ), 'node_type' => 'mail.heading', 'defaults' => [ 'text' => __( 'Your heading', 'core-blueprint' ), 'fontSize' => 28, 'align' => 'left', 'color' => '#1f2937', 'spacing' => 16 ], 'inspector' => [ [ 'key' => 'text', 'label' => __( 'Content', 'core-blueprint' ), 'type' => 'textarea' ], [ 'key' => 'fontSize', 'label' => __( 'Font size', 'core-blueprint' ), 'type' => 'number', 'min' => 16, 'max' => 48 ], [ 'key' => 'align', 'label' => __( 'Alignment', 'core-blueprint' ), 'type' => 'select', 'options' => $align ], [ 'key' => 'color', 'label' => __( 'Text color', 'core-blueprint' ), 'type' => 'color' ], [ 'key' => 'spacing', 'label' => __( 'Space after', 'core-blueprint' ), 'type' => 'number', 'min' => 0, 'max' => 48 ] ] ],
			[ 'id' => 'text', 'label' => __( 'Text', 'core-blueprint' ), 'node_type' => 'mail.text', 'defaults' => [ 'text' => __( 'Write your message here.', 'core-blueprint' ), 'fontSize' => 16, 'align' => 'left', 'color' => '#1f2937', 'spacing' => 16 ], 'inspector' => [ [ 'key' => 'text', 'label' => __( 'Content', 'core-blueprint' ), 'type' => 'textarea' ], [ 'key' => 'fontSize', 'label' => __( 'Font size', 'core-blueprint' ), 'type' => 'number', 'min' => 12, 'max' => 28 ], [ 'key' => 'align', 'label' => __( 'Alignment', 'core-blueprint' ), 'type' => 'select', 'options' => $align ], [ 'key' => 'color', 'label' => __( 'Text color', 'core-blueprint' ), 'type' => 'color' ], [ 'key' => 'spacing', 'label' => __( 'Space after', 'core-blueprint' ), 'type' => 'number', 'min' => 0, 'max' => 48 ] ] ],
			[ 'id' => 'button', 'label' => __( 'Button', 'core-blueprint' ), 'node_type' => 'mail.button', 'defaults' => [ 'label' => __( 'Continue', 'core-blueprint' ), 'url' => '{{action.url}}', 'align' => 'left', 'background' => '#2563eb', 'color' => '#ffffff', 'radius' => 6, 'spacing' => 20 ], 'inspector' => [ [ 'key' => 'label', 'label' => __( 'Label', 'core-blueprint' ), 'type' => 'text' ], [ 'key' => 'url', 'label' => __( 'URL', 'core-blueprint' ), 'type' => 'text' ], [ 'key' => 'align', 'label' => __( 'Alignment', 'core-blueprint' ), 'type' => 'select', 'options' => $align ], [ 'key' => 'background', 'label' => __( 'Background', 'core-blueprint' ), 'type' => 'color' ], [ 'key' => 'color', 'label' => __( 'Text color', 'core-blueprint' ), 'type' => 'color' ], [ 'key' => 'radius', 'label' => __( 'Corner radius', 'core-blueprint' ), 'type' => 'number', 'min' => 0, 'max' => 32 ], [ 'key' => 'spacing', 'label' => __( 'Space after', 'core-blueprint' ), 'type' => 'number', 'min' => 0, 'max' => 48 ] ] ],
			[ 'id' => 'image', 'label' => __( 'Image', 'core-blueprint' ), 'node_type' => 'mail.image', 'defaults' => [ 'url' => '', 'alt' => '', 'width' => 560, 'align' => 'center', 'spacing' => 20 ], 'inspector' => [ [ 'key' => 'url', 'label' => __( 'Image URL', 'core-blueprint' ), 'type' => 'url' ], [ 'key' => 'alt', 'label' => __( 'Alternative text', 'core-blueprint' ), 'type' => 'text' ], [ 'key' => 'width', 'label' => __( 'Width', 'core-blueprint' ), 'type' => 'number', 'min' => 1, 'max' => 800 ], [ 'key' => 'align', 'label' => __( 'Alignment', 'core-blueprint' ), 'type' => 'select', 'options' => $align ], [ 'key' => 'spacing', 'label' => __( 'Space after', 'core-blueprint' ), 'type' => 'number', 'min' => 0, 'max' => 48 ] ] ],
			[ 'id' => 'divider', 'label' => __( 'Divider', 'core-blueprint' ), 'node_type' => 'mail.divider', 'defaults' => [ 'color' => '#e5e7eb', 'spacing' => 20 ], 'inspector' => [ [ 'key' => 'color', 'label' => __( 'Color', 'core-blueprint' ), 'type' => 'color' ], [ 'key' => 'spacing', 'label' => __( 'Vertical space', 'core-blueprint' ), 'type' => 'number', 'min' => 0, 'max' => 48 ] ] ],
			[ 'id' => 'spacer', 'label' => __( 'Spacer', 'core-blueprint' ), 'node_type' => 'mail.spacer', 'defaults' => [ 'height' => 24 ], 'inspector' => [ [ 'key' => 'height', 'label' => __( 'Height', 'core-blueprint' ), 'type' => 'number', 'min' => 0, 'max' => 120 ] ] ],
		];

		foreach ( $definitions as $definition ) {
			$definition['provider'] = 'core';
			self::register( $definition );
		}
	}

	/** @internal tests */
	public static function _reset_for_testing(): void {
		self::$definitions = [];
		self::$booted = false;
	}

	private function __construct() {}
}
