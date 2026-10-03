<?php
declare(strict_types=1);
/**
 * IntegrationGrid - shared Core Admin presentation for extension integrations.
 *
 * Consumers own every integration's domain meaning, detection/readiness logic,
 * labels and destinations. Base owns only the generic presentation contract.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class IntegrationGrid {

	public const READY       = 'ready';
	public const NEEDS_SETUP = 'needs-setup';
	public const OPTIONAL    = 'optional';
	public const UNAVAILABLE = 'unavailable';

	/** Map IntegrationGrid semantics to the existing Status primitive. */
	private const STATUS_VARIANTS = [
		self::READY       => 'active',
		self::NEEDS_SETUP => 'warning',
		self::OPTIONAL    => 'idle',
		self::UNAVAILABLE => 'error',
	];

	/**
	 * Render a responsive integration-card grid.
	 *
	 * Each item accepts:
	 * - name: required human-readable integration name.
	 * - description: optional explanatory copy.
	 * - status: required one of ready, needs-setup, optional, unavailable.
	 * - status_label: required caller-localised visible status label.
	 * - action_url/action_label: optional CTA; both must be non-empty.
	 *
	 * Invalid items fail closed and are omitted. Unknown statuses are not
	 * coerced into a different meaning.
	 *
	 * @param array<int,array<string,mixed>> $items Integration descriptors.
	 */
	public static function render( array $items ): string {
		$cards = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$normalized = self::normalize_item( $item );
			if ( null === $normalized ) {
				continue;
			}

			$cards[] = self::render_card( $normalized );
		}

		if ( [] === $cards ) {
			return '';
		}

		return '<div class="cb-core-integration-grid">' . implode( '', $cards ) . '</div>';
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array{name:string,description:string,status:string,status_label:string,action_url:string,action_label:string}|null
	 */
	private static function normalize_item( array $item ): ?array {
		$name         = isset( $item['name'] ) && is_scalar( $item['name'] ) ? trim( (string) $item['name'] ) : '';
		$status       = isset( $item['status'] ) && is_scalar( $item['status'] ) ? trim( (string) $item['status'] ) : '';
		$status_label = isset( $item['status_label'] ) && is_scalar( $item['status_label'] )
			? trim( (string) $item['status_label'] )
			: '';

		if ( '' === $name || '' === $status_label || ! isset( self::STATUS_VARIANTS[ $status ] ) ) {
			return null;
		}

		$description = isset( $item['description'] ) && is_scalar( $item['description'] )
			? trim( (string) $item['description'] )
			: '';
		$action_url = isset( $item['action_url'] ) && is_scalar( $item['action_url'] )
			? trim( (string) $item['action_url'] )
			: '';
		$action_label = isset( $item['action_label'] ) && is_scalar( $item['action_label'] )
			? trim( (string) $item['action_label'] )
			: '';

		// A partial CTA is not actionable and therefore fails closed to no CTA.
		if ( '' === $action_url || '' === $action_label ) {
			$action_url   = '';
			$action_label = '';
		}

		return [
			'name'         => $name,
			'description'  => $description,
			'status'       => $status,
			'status_label' => $status_label,
			'action_url'   => $action_url,
			'action_label' => $action_label,
		];
	}

	/**
	 * @param array{name:string,description:string,status:string,status_label:string,action_url:string,action_label:string} $item
	 */
	private static function render_card( array $item ): string {
		$out  = '<article class="cb-core-integration-card">';
		$out .= '<div class="cb-core-integration-card__header">';
		$out .= '<h3 class="cb-core-integration-card__title">' . esc_html( $item['name'] ) . '</h3>';
		$out .= Status::render( self::STATUS_VARIANTS[ $item['status'] ], $item['status_label'] );
		$out .= '</div>';

		if ( '' !== $item['description'] ) {
			$out .= '<p class="cb-core-integration-card__description">' . esc_html( $item['description'] ) . '</p>';
		}

		if ( '' !== $item['action_url'] && '' !== $item['action_label'] ) {
			$out .= '<footer class="cb-core-integration-card__footer">';
			$out .= sprintf(
				'<a class="button cb-core-button cb-core-button--secondary cb-core-button--compact" href="%1$s">%2$s</a>',
				esc_url( $item['action_url'] ),
				esc_html( $item['action_label'] )
			);
			$out .= '</footer>';
		}

		$out .= '</article>';
		return $out;
	}
}
