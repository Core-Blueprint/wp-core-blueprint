<?php
declare(strict_types=1);

/**
 * Focused AI Governance ownership sentinel for the destructive uninstall flow.
 *
 * This script talks directly to the disposable uninstall database so the Base
 * plugin may already have been deleted when the verify stage runs.
 */

$stage = isset( $argv[1] ) ? (string) $argv[1] : '';
if ( ! in_array( $stage, [ 'seed', 'verify' ], true ) ) {
	fwrite( STDERR, "Usage: php tests/uninstall/ai-governance-sentinel.php <seed|verify>\n" );
	exit( 64 );
}

function cb_ai_uninstall_env( string $name, string $default = '' ): string {
	$value = getenv( $name );
	return false === $value ? $default : (string) $value;
}

function cb_ai_uninstall_expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function cb_ai_uninstall_db(): mysqli {
	$host = cb_ai_uninstall_env( 'WP_DB_HOST', '127.0.0.1:3306' );
	$port = 3306;
	if ( preg_match( '/^([^:]+):([0-9]+)$/D', $host, $matches ) ) {
		$host = $matches[1];
		$port = (int) $matches[2];
	}

	$db = new mysqli(
		$host,
		cb_ai_uninstall_env( 'WP_DB_USER', 'root' ),
		cb_ai_uninstall_env( 'WP_DB_PASSWORD', '' ),
		cb_ai_uninstall_env( 'WP_DB_NAME', 'wordpress_test' ),
		$port
	);
	if ( $db->connect_errno ) {
		throw new RuntimeException( 'Could not connect to AI Governance uninstall database: ' . $db->connect_error );
	}
	return $db;
}

function cb_ai_uninstall_option( mysqli $db, string $options_table, string $name ): ?string {
	$stmt = $db->prepare( "SELECT option_value FROM `{$options_table}` WHERE option_name = ? LIMIT 1" );
	if ( false === $stmt ) {
		throw new RuntimeException( 'Could not prepare AI Governance option lookup.' );
	}
	$stmt->bind_param( 's', $name );
	$stmt->execute();
	$result = $stmt->get_result();
	$row = $result->fetch_assoc();
	$stmt->close();
	return is_array( $row ) && array_key_exists( 'option_value', $row ) ? (string) $row['option_value'] : null;
}

try {
	$prefix = cb_ai_uninstall_env( 'CB_UNINSTALL_TABLE_PREFIX', 'cbuninstall_' );
	cb_ai_uninstall_expect( 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $prefix ), 'Unsafe uninstall table prefix.' );

	$options_table = $prefix . 'options';
	$activity_table = $prefix . 'cb_core_ai_activity';
	foreach ( [ $options_table, $activity_table ] as $table ) {
		cb_ai_uninstall_expect( 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $table ), 'Unsafe AI Governance table identifier.' );
	}

	$db = cb_ai_uninstall_db();
	$stmt = $db->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1' );
	if ( false === $stmt ) {
		throw new RuntimeException( 'Could not prepare AI Governance table lookup.' );
	}
	$stmt->bind_param( 's', $activity_table );
	$stmt->execute();
	$table_result = $stmt->get_result();
	$table_exists = null !== $table_result->fetch_row();
	$stmt->close();

	if ( 'seed' === $stage ) {
		cb_ai_uninstall_expect( $table_exists, 'AI Activity table was not created before uninstall.' );
		cb_ai_uninstall_expect(
			'1.1' === cb_ai_uninstall_option( $db, $options_table, 'cb_core_ai_activity_db_version' ),
			'AI Activity schema marker was not established before uninstall.'
		);

		$retention_name = 'cb_core_ai_activity_retention_days';
		$retention_value = '17';
		$stmt = $db->prepare(
			"INSERT INTO `{$options_table}` (option_name, option_value, autoload) VALUES (?, ?, 'off')
			 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)"
		);
		if ( false === $stmt ) {
			throw new RuntimeException( 'Could not prepare AI Governance retention sentinel.' );
		}
		$stmt->bind_param( 'ss', $retention_name, $retention_value );
		$stmt->execute();
		$stmt->close();
		cb_ai_uninstall_expect(
			'17' === cb_ai_uninstall_option( $db, $options_table, $retention_name ),
			'Could not establish AI Governance retention sentinel.'
		);

		$db->close();
		fwrite( STDOUT, "[A3 uninstall] ai-governance-seed PASS\n" );
		exit( 0 );
	}

	cb_ai_uninstall_expect( ! $table_exists, 'AI Activity table survived Base uninstall.' );
	cb_ai_uninstall_expect(
		null === cb_ai_uninstall_option( $db, $options_table, 'cb_core_ai_activity_db_version' ),
		'AI Activity schema marker survived Base uninstall.'
	);
	cb_ai_uninstall_expect(
		null === cb_ai_uninstall_option( $db, $options_table, 'cb_core_ai_activity_retention_days' ),
		'AI Activity retention setting survived Base uninstall.'
	);

	$db->close();
	fwrite( STDOUT, "[A3 uninstall] ai-governance-verify PASS\n" );
	exit( 0 );
} catch ( Throwable $e ) {
	fwrite( STDERR, "[A3 uninstall] ai-governance-{$stage} FAIL: {$e->getMessage()}\n" );
	exit( 1 );
}
