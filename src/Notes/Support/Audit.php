<?php
declare(strict_types=1);
namespace CoreBlueprint\Core\Notes\Support;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Audit {
    public static function log( string $event, array $context = [] ): void {
        AuditLog::log(
            $event,
            'info',
            $context
        );
    }
}
