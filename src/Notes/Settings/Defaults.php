<?php
declare(strict_types=1);
namespace CoreBlueprint\Core\Notes\Settings;

use CoreBlueprint\Core\Notes\Repository;

defined( 'ABSPATH' ) || exit;

final class Defaults {

	public static function values(): array {
        return [
            'enabled'               => false,
            'default_type'          => 'General',
            'default_status'        => 'Backlog',
            'default_assigned_to'   => 0,
            'details_initial_state' => 'remember',
            'default_layout'        => 'list',
        ];
    }

    public static function sanitize( array $settings ): array {
        $defaults = self::values();

        $details_state = isset( $settings['details_initial_state'] )
            ? sanitize_key( (string) $settings['details_initial_state'] )
            : $defaults['details_initial_state'];

        if ( ! in_array( $details_state, [ 'remember', 'closed', 'open' ], true ) ) {
            $details_state = $defaults['details_initial_state'];
        }

        $layout = isset( $settings['default_layout'] )
            ? sanitize_key( (string) $settings['default_layout'] )
            : $defaults['default_layout'];

        if ( ! in_array( $layout, [ 'list', 'grid-2', 'grid-3' ], true ) ) {
            $layout = $defaults['default_layout'];
        }

        return [
            'enabled'               => (bool) ( $settings['enabled'] ?? $defaults['enabled'] ),
            'default_type'          => Repository::sanitize_type( (string) ( $settings['default_type'] ?? $defaults['default_type'] ) ),
            'default_status'        => Repository::sanitize_status( (string) ( $settings['default_status'] ?? $defaults['default_status'] ) ),
            'default_assigned_to'   => self::sanitize_assigned_default( $settings['default_assigned_to'] ?? $defaults['default_assigned_to'] ),
            'details_initial_state' => $details_state,
            'default_layout'        => $layout,
        ];
    }

    private static function sanitize_assigned_default( $value ): int {
        $assigned = (int) $value;
        return $assigned >= -1 ? $assigned : 0;
    }
}
