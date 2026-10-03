<?php
declare(strict_types=1);
/**
 * DetailRows - shared Core Admin presentation for object/resource detail rows.
 *
 * Consumers own section/card context, row inventory, domain meaning, readiness
 * logic, labels and destinations. Base owns only the generic row anatomy and
 * presentation contract.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class DetailRows {

	/** Status semantics are the public variants of the existing Status primitive. */
	private const STATUS_VARIANTS = [
		'active',
		'ready',
		'warning',
		'error',
		'idle',
	];

	/**
	 * Render one or more detail rows.
	 *
	 * Each item accepts:
	 * - name: required human-readable row name.
	 * - description: optional detail text.
	 * - status: optional Status primitive variant.
	 * - status_label: required caller-localised visible label when status is valid.
	 * - action_url/action_label: optional CTA; both must be non-empty.
	 *
	 * Invalid row names fail closed and are omitted. Invalid/incomplete optional
	 * status and CTA pairs fail closed to no status/action without discarding the
	 * otherwise valid row. Consumer text and URLs are escaped by Base.
	 *
	 * @param array<int,array<string,mixed>> $items Row descriptors.
	 */
	public static function render( array $items ): string {
		$rows = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$normalized = self::normalize_item( $item );
			if ( null === $normalized ) {
				continue;
			}

			$rows[] = self::render_row( $normalized );
		}

		if ( [] === $rows ) {
			return '';
		}

		return '<div class="cb-core-detail-rows">' . implode( '', $rows ) . '</div>';
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array{name:string,description:string,status:string,status_label:string,action_url:string,action_label:string}|null
	 */
	private static function normalize_item( array $item ): ?array {
		$name = isset( $item['name'] ) && is_scalar( $item['name'] )
			? trim( (string) $item['name'] )
			: '';

		if ( '' === $name ) {
			return null;
		}

		$description = isset( $item['description'] ) && is_scalar( $item['description'] )
			? trim( (string) $item['description'] )
			: '';
		$status = isset( $item['status'] ) && is_scalar( $item['status'] )
			? trim( (string) $item['status'] )
			: '';
		$status_label = isset( $item['status_label'] ) && is_scalar( $item['status_label'] )
			? trim( (string) $item['status_label'] )
			: '';
		$action_url = isset( $item['action_url'] ) && is_scalar( $item['action_url'] )
			? trim( (string) $item['action_url'] )
			: '';
		$action_label = isset( $item['action_label'] ) && is_scalar( $item['action_label'] )
			? trim( (string) $item['action_label'] )
			: '';

		// Status is optional. Unknown or incomplete status metadata is not coerced.
		if ( '' === $status_label || ! in_array( $status, self::STATUS_VARIANTS, true ) ) {
			$status       = '';
			$status_label = '';
		}

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
	private static function render_row( array $item ): string {
		$out  = '<div class="cb-core-detail-row">';
		$out .= '<div class="cb-core-detail-row__content">';
		$out .= '<div class="cb-core-detail-row__name">' . esc_html( $item['name'] ) . '</div>';

		if ( '' !== $item['description'] ) {
			$out .= '<div class="cb-core-detail-row__description">' . esc_html( $item['description'] ) . '</div>';
		}

		$out .= '</div>';

		if ( '' !== $item['status'] && '' !== $item['status_label'] ) {
			$out .= '<div class="cb-core-detail-row__status">';
			$out .= Status::render( $item['status'], $item['status_label'] );
			$out .= '</div>';
		}

		if ( '' !== $item['action_url'] && '' !== $item['action_label'] ) {
			$out .= '<div class="cb-core-detail-row__action">';
			$out .= sprintf(
				'<a class="button cb-core-button cb-core-button--secondary cb-core-button--compact" href="%1$s">%2$s</a>',
				esc_url( $item['action_url'] ),
				esc_html( $item['action_label'] )
			);
			$out .= '</div>';
		}

		$out .= '</div>';
		return $out;
	}
}
