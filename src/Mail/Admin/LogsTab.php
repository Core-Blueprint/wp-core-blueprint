<?php
declare(strict_types=1);
/**
 * Shared Mail Log renderer for both Core Blueprint > Mail and Logs > Mail Log.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail\Admin;

use CoreBlueprint\Core\Admin\Admin;
use CoreBlueprint\Core\Admin\Pages\Logs\TabRegistry;
use CoreBlueprint\Core\Admin\TabNav;
use CoreBlueprint\Core\Log\TimeFilter;
use CoreBlueprint\Core\Mail\Log\Repository;
use CoreBlueprint\Core\Mail\Settings;

defined( 'ABSPATH' ) || exit;

final class LogsTab {

	public const SLUG = 'mail';

	public static function register(): void {
		TabRegistry::register( self::SLUG, [
			'label'    => __( 'Mail Log', 'core-blueprint' ),
			'priority' => 30,
			'renderer' => [ __CLASS__, 'render_central' ],
		] );
	}

	public static function render_central( string $tab, array $tab_labels ): void {
		$html = self::html( Admin::LOGS_SLUG, $tab );
		echo TabNav::inject( $html, Admin::LOGS_SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function html( string $page_slug, string $tab_slug ): string {
		$current_period   = isset( $_GET['period'] ) ? TimeFilter::sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ) ) ) : TimeFilter::DEFAULT_PRESET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_provider = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_search   = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = Repository::query( [
			'status'   => $current_status,
			'provider' => $current_provider,
			'search'   => $current_search,
			'since'    => TimeFilter::since_mysql( $current_period ),
			'page'     => $current_page,
			'per_page' => 50,
		] );

		$rows        = $result['rows'];
		$total       = (int) $result['total'];
		$total_pages = (int) $result['total_pages'];
		$retention   = Settings::retention_days();
		$result_notice = Actions::pull_result();
		$providers   = [
			'brevo' => __( 'Brevo', 'core-blueprint' ),
			'smtp'  => __( 'Generic SMTP', 'core-blueprint' ),
		];

		ob_start();
		include CB_CORE_DIR . 'templates/mail-log.php';
		return (string) ob_get_clean();
	}

	public static function format_addresses( array $addresses ): string {
		$parts = [];
		foreach ( $addresses as $address ) {
			if ( empty( $address['email'] ) ) {
				continue;
			}
			$name = trim( (string) ( $address['name'] ?? '' ) );
			$email = (string) $address['email'];
			$parts[] = '' !== $name ? $name . ' <' . $email . '>' : $email;
		}
		return implode( ', ', $parts );
	}
}
