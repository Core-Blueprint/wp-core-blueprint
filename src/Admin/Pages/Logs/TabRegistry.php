<?php
declare(strict_types=1);
/**
 * TabRegistry - open registry for tabs on the Logs admin page.
 *
 * The Logs page must not hardcode extension-specific tabs or know about every
 * subsystem that may contribute one. That would invert the dependency direction
 * between the platform and its extensions.
 *
 * This registry flips that around. Core Blueprint registers its own
 * built-in tabs (audit, system, maintenance) through the same API that any
 * CB plugin or subsystem uses. Beacon registers the Connection tab from
 * inside its own boot_paired_hooks() - so when pairing isn't active, the
 * tab simply isn't registered and the page doesn't need to guard for it.
 *
 * Future CB plugins can contribute their own tabs the same way:
 *
 *     TabRegistry::register( 'invoice-activity', [
 *         'label'     => __( 'Invoices', 'cb-invoice' ),
 *         'priority'  => 40,
 *         'condition' => fn() => true,
 *         'renderer'  => [ InvoiceLogTab::class, 'render' ],
 *     ] );
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Admin\Pages\Logs;

defined( 'ABSPATH' ) || exit;

final class TabRegistry {

	/**
	 * @var array<string, array{
	 *     label: string,
	 *     priority: int,
	 *     condition: callable|null,
	 *     renderer: callable,
	 * }>
	 */
	private static array $tabs = [];

	/**
	 * Register a Logs page tab.
	 *
	 * @param string $slug Tab slug used in the ?tab= URL parameter.
	 * @param array  $spec {
	 *     @type string        $label     Human-readable label for the tab nav.
	 *     @type int           $priority  Sort order, lower = left (default 50).
	 *     @type callable|null $condition Optional fn():bool - tab is only shown
	 *                                     when this returns true. Null = always.
	 *     @type callable      $renderer  fn( string $slug, array $tab_labels ): void
	 *                                     - responsible for producing the tab body
	 *                                     (including calling inject_tab_nav).
	 * }
	 */
	public static function register( string $slug, array $spec ): void {
		if ( '' === $slug || ! isset( $spec['label'], $spec['renderer'] ) ) {
			return;
		}
		if ( ! is_callable( $spec['renderer'] ) ) {
			return;
		}

		self::$tabs[ $slug ] = [
			'label'     => (string) $spec['label'],
			'priority'  => isset( $spec['priority'] ) ? (int) $spec['priority'] : 50,
			'condition' => isset( $spec['condition'] ) && is_callable( $spec['condition'] ) ? $spec['condition'] : null,
			'renderer'  => $spec['renderer'],
		];
	}

	/**
	 * Unregister a tab. Defensive - allows a parent plugin to suppress a
	 * tab contributed by a sibling if it collides.
	 */
	public static function unregister( string $slug ): void {
		unset( self::$tabs[ $slug ] );
	}

	/**
	 * All registered tabs, keyed by slug, sorted by priority ascending.
	 *
	 * Does NOT run the condition callbacks - useful for diagnostics.
	 */
	public static function all(): array {
		$copy = self::$tabs;
		uasort( $copy, static fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
		return $copy;
	}

	/**
	 * Tabs whose condition callback returns true (or which have no condition).
	 * This is the list the Logs page actually renders.
	 *
	 * @return array<string, array> Same shape as all(), filtered.
	 */
	public static function visible(): array {
		$out = [];
		foreach ( self::all() as $slug => $spec ) {
			if ( null !== $spec['condition'] ) {
				if ( ! (bool) call_user_func( $spec['condition'] ) ) {
					continue;
				}
			}
			$out[ $slug ] = $spec;
		}
		return $out;
	}

	/** Get a single tab by slug, or null if unregistered. */
	public static function get( string $slug ): ?array {
		return self::$tabs[ $slug ] ?? null;
	}
}
