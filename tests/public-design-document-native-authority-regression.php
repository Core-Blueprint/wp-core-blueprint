<?php
declare(strict_types=1);

/** C3 clean-v1 primary DB admission, source-only; no WordPress or SQL writes. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	function get_current_blog_id(): int { return $GLOBALS['cb_c3_blog']; }
	function cb_c3_check( bool $condition, string $reason ): void {
		if ( ! $condition ) throw new \RuntimeException( 'C3 authority regression: ' . $reason );
	}
	final class C3FakeDb {
		public string $options = 'wp_options';
		public string $last_error = '';
		public bool $fail = false;
		public array $rows = [];
		public array $reads = [];
		public function prepare( string $sql, string $table, string $option ): string {
			cb_c3_check( str_contains( $sql, '%i' ) && str_contains( $sql, '%s' ), 'prepared identifiers' );
			cb_c3_check( $table === $this->options, 'same-site table' );
			return $option;
		}
		public function get_row( string $option, mixed $format ): ?array {
			cb_c3_check( ARRAY_A === $format, 'associative primary DB read' );
			$this->reads[] = $option;
			if ( $this->fail ) {
				$this->last_error = 'simulated primary DB error';
				return null;
			}
			return $this->rows[$option] ?? null;
		}
	}
	$wpdb = new C3FakeDb();
	$GLOBALS['cb_c3_blog'] = 1;
}
namespace CoreBlueprint\Core\Design\Document {
	final class Repository {
		public const MAIL = 'base.mail-template';
		public const REPORTS = 'base.maintenance-report-design';
	}
	final class Schema {
		public static bool $healthy = true;
		public static function healthy(): bool { return self::$healthy; }
	}
}
namespace CoreBlueprint\Core\Mail\Designer {
	final class TemplateRepository { public const OPTION = 'cb_core_mail_template_overrides'; }
}
namespace CoreBlueprint\Core {
	final class Settings {
		public static function defaults(): array {
			return [ 'reports' => [
				'branding' => [ 'provider_name' => '' ],
				'composer' => [ 'maintenance' => [ 'schema_version' => 1 ] ],
			] ];
		}
	}
}
namespace {
	require_once dirname( __DIR__ ) . '/src/Design/Document/Authority.php';
	use CoreBlueprint\Core\Design\Document\Authority;
	use CoreBlueprint\Core\Design\Document\Repository;
	use CoreBlueprint\Core\Design\Document\Schema;

	cb_c3_check( 'active' === Authority::inspect()['status'], 'clean fresh v1 admitted' );
	cb_c3_check( 'active' === Authority::inspect( Repository::MAIL )['status'], 'clean Mail admitted' );
	cb_c3_check( 'active' === Authority::inspect( Repository::REPORTS )['status'], 'clean Reports admitted' );
	cb_c3_check( 'blocked' === Authority::inspect( 'unknown.document' )['status'], 'unknown type fails closed' );
	cb_c3_check( count( $wpdb->reads ) === 6, 'six real primary DB option reads' );
	$defaults = \CoreBlueprint\Core\Settings::defaults()['reports'];
	$wpdb->rows['cb_core_settings'] = [ 'option_value' => serialize( [
		'reports' => $defaults, 'other_policy' => [ 'retention_days' => 77 ],
	] ) ];
	cb_c3_check( 'active' === Authority::inspect()['status'], 'uncustomized Reports and unrelated policy' );
	$custom = unserialize( $wpdb->rows['cb_core_settings']['option_value'] );
	$custom['reports']['branding']['provider_name'] = 'historical customization';
	$wpdb->rows['cb_core_settings']['option_value'] = serialize( $custom );
	cb_c3_check( 'blocked' === Authority::inspect()['status'], 'custom Reports branding blocks' );
	cb_c3_check( 'blocked' === Authority::inspect( Repository::REPORTS )['status'], 'Reports customization still blocked' );
	$wpdb->rows['cb_core_settings']['option_value'] = 'broken-serialized-settings';
	cb_c3_check( 'blocked' === Authority::inspect()['status'], 'corrupt Settings blocks' );
	$wpdb->rows['cb_core_settings']['option_value'] = serialize( [ 'reports' => [] ] );
	cb_c3_check( 'active' === Authority::inspect()['status'], 'sparse clean Reports settings' );

	foreach ( [
		Authority::ACTIVE_OPTION, Authority::EVER_ACTIVE_OPTION,
		'cb_core_design_docs_prepared_v1', 'cb_core_design_docs_migration_backup_v1',
		'cb_core_mail_template_overrides',
	] as $key ) {
		$wpdb->rows[$key] = [ 'option_value' => '' ];
		cb_c3_check( 'blocked' === Authority::inspect()['status'], 'legacy source refused: ' . $key );
		$reports_expected = 'cb_core_mail_template_overrides' === $key ? 'active' : 'blocked';
		cb_c3_check( $reports_expected === Authority::inspect( Repository::REPORTS )['status'], 'Reports scoped legacy source: ' . $key );
		unset( $wpdb->rows[$key] );
	}
	// The full historical Mail value is never deleted, read into a native default
	// or silently rewritten when a Reports document is admitted.
	$wpdb->rows['cb_core_mail_template_overrides'] = [ 'option_value' => 'historical Mail customizations' ];
	$preserved_rows = $wpdb->rows;
	cb_c3_check( 'active' === Authority::inspect( Repository::REPORTS )['status'], 'Reports ignores only Mail sentinel' );
	cb_c3_check( 'blocked' === Authority::inspect( Repository::MAIL )['status'], 'Mail remains blocked' );
	cb_c3_check( $preserved_rows === $wpdb->rows, 'Mail history remains byte-for-byte unchanged' );
	unset( $wpdb->rows['cb_core_mail_template_overrides'] );
	$wpdb->fail = true;
	cb_c3_check( 'blocked' === Authority::inspect()['status'], 'DB errors fail closed' );
	cb_c3_check( 'blocked' === Authority::inspect( Repository::REPORTS )['status'], 'Reports DB error fails closed' );
	$wpdb->fail = false;
	Schema::$healthy = false;
	cb_c3_check( 'blocked' === Authority::inspect()['status'], 'unhealthy schema fails closed' );
	cb_c3_check( 'blocked' === Authority::inspect( Repository::REPORTS )['status'], 'Reports schema fails closed' );
	Schema::$healthy = true;
	$GLOBALS['cb_c3_blog'] = 0;
	cb_c3_check( 'blocked' === Authority::inspect()['status'], 'invalid site identity fails closed' );
	cb_c3_check( 'blocked' === Authority::inspect( Repository::REPORTS )['status'], 'Reports site identity fails closed' );

	$root = dirname( __DIR__ );
	$transport = file_get_contents( $root . '/src/Design/Editor/PlatformTransport.php' );
	$mail = file_get_contents( $root . '/src/Mail/Designer/TemplateRepository.php' );
	$reports = file_get_contents( $root . '/src/Reports/DesignDocumentAdapter.php' );
	$reports_profile = file_get_contents( $root . '/src/Profiles/ReportsProfileTransaction.php' );
	$reports_section = file_get_contents( $root . '/src/Profiles/Sections/ReportsSection.php' );
	$branding = file_get_contents( $root . '/src/Ajax/Handlers/Branding.php' );
	cb_c3_check( substr_count( $transport, 'Authority::inspect(' ) === 4, 'REST context and Save guarded' );
	cb_c3_check( str_contains( $transport, 'new TransactionalSave(' ), 'single canonical Save' );
	cb_c3_check( str_contains( $transport, "Authority::inspect( \$identity['type'] )" ), 'context and save use resolved type' );
	cb_c3_check( str_contains( $transport, 'Authority::inspect( $item->resource_type )' ), 'transaction callbacks use immutable intent type' );
	cb_c3_check( str_contains( $mail, 'Authority::inspect()' ), 'Mail reader guarded' );
	cb_c3_check( str_contains( $reports, 'Authority::inspect( Repository::REPORTS )' ), 'Reports reader typed guard' );
	cb_c3_check( substr_count( $reports_profile, 'Authority::inspect( Repository::REPORTS )' ) === 2, 'Reports Profile transaction typed guard' );
	cb_c3_check( substr_count( $reports_section, 'Authority::inspect( Repository::REPORTS )' ) === 3, 'Reports Profile section typed guard' );
	cb_c3_check( ! str_contains( $branding, 'Settings::set_paths(' ), 'legacy AJAX Settings writes removed' );
	cb_c3_check( str_contains( $branding, "Request::nonce( 'cb_core_admin' )" )
		&& str_contains( $branding, 'self::require_manage_branding()' ), 'AJAX security preserved' );
	echo "Base C3 native-v1 fail-closed authority source regression: PASS\n";
}
