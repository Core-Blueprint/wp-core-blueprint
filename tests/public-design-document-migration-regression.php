<?php
declare(strict_types=1);

/**
 * A2.2 SOURCE-ONLY migration/failure-path fixture. No WordPress, Docker or SQL.
 * Real InnoDB locking, cache and two-worker proof needs an independent Level 2 GO.
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	function get_current_blog_id(): int { return $GLOBALS['test_blog_id'] ?? 1; }
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags | JSON_THROW_ON_ERROR );
	}
	function is_serialized( mixed $raw ): bool {
		return is_string( $raw ) && 1 === preg_match( '/^(?:a|s|i|b|d|O|C|N):/', $raw );
	}
	class MigrationFakeDb {
		public string $prefix = 'wp_';
		public string $options = 'wp_options';
		public string $last_error = '';
		public array $options_by_site = [];
		public array $docs_by_site = [];
		public bool $fail_marker_insert = false;
		public bool $fail_commit = false;
		public string $options_engine = 'InnoDB';
		public bool $unique_option_name = true;
		public mixed $after_start = null;
		public bool $active = false;
		private array $statements = [];
		private array $savepoint = [];

		public function switch_blog( int $id ): void {
			if ( $this->active ) { throw new \RuntimeException( 'Switch inside transaction.' ); }
			$GLOBALS['test_blog_id'] = $id;
			$this->prefix = 1 === $id ? 'wp_' : 'wp_' . $id . '_';
			$this->options = $this->prefix . 'options';
		}
		public function put_option( string $name, mixed $value, string $autoload = 'no' ): void {
			$this->options_by_site[$this->options][$name] = [
				'option_name' => $name, 'option_value' => serialize( $value ), 'autoload' => $autoload,
			];
		}
		public function prepare( string $sql, mixed ...$args ): string {
			$token = 'query:' . count( $this->statements );
			$this->statements[$token] = [ $sql, $args ];
			return $token;
		}
		private function parts( string $query ): array {
			return $this->statements[$query] ?? [ $query, [] ];
		}
		public function get_row( string $query, mixed $mode = null ): ?array {
			[ $sql, $args ] = $this->parts( $query );
			if ( str_contains( $sql, 'SELECT option_name, option_value' ) ) {
				return $this->options_by_site[$args[0]][$args[1]] ?? null;
			}
			if ( str_contains( $sql, 'information_schema.TABLES' ) ) {
				return [ 'engine' => $this->options_engine ];
			}
			throw new \RuntimeException( 'Unexpected get_row SQL.' );
		}
		public function get_var( string $query ): mixed {
			[ $sql, $args ] = $this->parts( $query );
			if ( 'SELECT @@in_transaction' === $sql ) { return $this->active ? '1' : '0'; }
			if ( str_contains( $sql, 'SELECT COUNT(*)' ) ) {
				return (string) count( $this->docs_by_site[$args[0]] ?? [] );
			}
			throw new \RuntimeException( 'Unexpected get_var SQL.' );
		}
		public function get_results( string $query, mixed $mode = null ): array {
			[ $sql, $args ] = $this->parts( $query );
			if ( str_contains( $sql, 'information_schema.STATISTICS' ) ) {
				return [
					[ 'index_name' => 'option_name', 'non_unique' => $this->unique_option_name ? 0 : 1,
						'seq' => 1, 'column_name' => 'option_name', 'sub_part' => null ],
				];
			}
			if ( str_contains( $sql, 'SELECT identity_hash, document_type' ) ) {
				return array_values( array_map(
					static fn ( array $row ): array => [
						'identity_hash' => $row['identity_hash'],
						'document_type' => $row['type'],
						'document_key' => $row['key'],
						'revision' => $row['revision'],
					],
					$this->docs_by_site[$args[0]] ?? []
				) );
			}
			throw new \RuntimeException( 'Unexpected get_results SQL.' );
		}
		public function query( string $query ): int|false {
			[ $sql, $args ] = $this->parts( $query );
			if ( 'START TRANSACTION' === $sql ) {
				$this->savepoint = [ $this->options_by_site, $this->docs_by_site ];
				$this->active = true;
				if ( is_callable( $this->after_start ) ) {
					$callback = $this->after_start;
					$this->after_start = null;
					$callback( $this );
				}
				return 0;
			}
			if ( 'ROLLBACK' === $sql ) {
				[ $this->options_by_site, $this->docs_by_site ] = $this->savepoint;
				$this->active = false;
				return 0;
			}
			if ( 'COMMIT' === $sql ) {
				$this->active = false;
				return $this->fail_commit ? false : 0;
			}
			if ( str_contains( $sql, 'INSERT INTO' ) && str_contains( $sql, 'option_name' ) ) {
				[ $table, $name, $value, $autoload ] = $args;
				if ( isset( $this->options_by_site[$table][$name] )
					|| $this->fail_marker_insert && $name === \CoreBlueprint\Core\Design\Document\Migration::PREPARED_OPTION ) {
					return false;
				}
				$this->options_by_site[$table][$name] = [
					'option_name' => $name, 'option_value' => $value, 'autoload' => $autoload,
				];
				return 1;
			}
			throw new \RuntimeException( 'Unexpected mutation in source-only test.' );
		}
	}
}
namespace CoreBlueprint\Core\Design\Document {
	final class Schema {
		public static bool $available = true;
		public static function healthy(): bool { return self::$available; }
		public static function table_name(): string {
			global $wpdb;
			return $wpdb->prefix . 'cb_design_documents';
		}
	}
	final class Repository {
		public const MAIL = 'base.mail-template';
		public const REPORTS = 'base.maintenance-report-design';
		public static int $fails_after = 0;
		public static function valid_identity( string $type, string $key ): bool {
			return self::MAIL === $type
				? strlen( $key ) <= 191 && 1 === preg_match( '/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $key )
				: self::REPORTS === $type && 'maintenance' === $key;
		}
		public static function valid_document( string $type, array $doc ): bool {
			return ( self::MAIL === $type
				? is_string( $doc['subject'] ?? null ) && '' !== trim( $doc['subject'] )
					&& is_array( $doc['project'] ?? null ) && ! array_is_list( $doc['project'] )
				: self::REPORTS === $type && is_array( $doc['branding'] ?? null )
					&& is_array( $doc['composer'] ?? null ) )
				&& strlen( (string) \wp_json_encode( $doc ) ) <= 4194304;
		}
		public static function get( string $type, string $key ): array {
			global $wpdb;
			$identity = hash( 'sha256', $type . "\0" . $key );
			$row = $wpdb->docs_by_site[Schema::table_name()][$identity] ?? null;
			return null === $row ? [ 'status' => 'missing' ] : [
				'status' => 'found', 'revision' => $row['revision'], 'document' => $row['document'],
			];
		}
		public static function create( string $type, string $key, string $expected, array $doc ): array {
			global $wpdb;
			if ( self::$fails_after > 0 && 0 === --self::$fails_after ) {
				return [ 'status' => 'error' ];
			}
			$hash = hash( 'sha256', $type . "\0" . $key );
			$table = Schema::table_name();
			if ( isset( $wpdb->docs_by_site[$table][$hash] ) ) {
				return [ 'status' => 'conflict' ];
			}
			$wpdb->docs_by_site[$table][$hash] = [
				'identity_hash' => $hash, 'type' => $type, 'key' => $key,
				'revision' => '1', 'document' => $doc,
			];
			return [ 'status' => 'saved' ];
		}
	}
}
namespace CoreBlueprint\Core\Mail\Designer {
	final class TemplateRepository { public const OPTION = 'cb_core_mail_template_overrides'; }
}
namespace CoreBlueprint\Core\Reports {
	final class ReportBranding {
		public static function settings_defaults(): array {
			return [ 'logo_attachment_id' => 0, 'show_logo' => true,
				'provider_name' => '', 'provider_contact' => '',
				'accent_color' => '#0064c8', 'surface_style' => 'cards',
				'density' => 'comfortable', 'corner_style' => 'soft', 'text_scale' => 'standard' ];
		}
	}
	final class ReportBrandingInput {
		public static function normalize( array $branding ): array {
			$allowed = array_keys( ReportBranding::settings_defaults() );
			if ( array_diff( array_keys( $branding ), $allowed ) ) { unset( $branding['unknown'] ); }
			if ( isset( $branding['provider_name'] ) && strlen( $branding['provider_name'] ) > 120 ) {
				$branding['provider_name'] = substr( $branding['provider_name'], 0, 120 );
			}
			return $branding;
		}
	}
}
namespace CoreBlueprint\Core\Reports\Composer {
	final class MaintenanceTemplate {
		public static function defaults(): array {
			return [ 'schema_version' => 1, 'blocks' => [ [ 'id' => 'header', 'type' => 'header' ] ] ];
		}
		public static function normalize( array $template ): array {
			if ( isset( $template['blocks'] ) ) {
				$template['blocks'] = array_values( array_filter(
					$template['blocks'], static fn ( array $b ): bool => 'unknown' !== ( $b['type'] ?? '' )
				) );
			}
			return $template;
		}
	}
}
namespace {
	require_once dirname( __DIR__ ) . '/src/Design/Document/MigrationInventory.php';
	require_once dirname( __DIR__ ) . '/src/Design/Document/MigrationBackup.php';
	require_once dirname( __DIR__ ) . '/src/Design/Document/Migration.php';
	use CoreBlueprint\Core\Design\Document\Migration;
	use CoreBlueprint\Core\Design\Document\MigrationInventory;
	use CoreBlueprint\Core\Design\Document\MigrationBackup;
	use CoreBlueprint\Core\Design\Document\Repository;

	function migration_check( bool $pass, string $case ): void {
		if ( ! $pass ) { throw new \RuntimeException( 'Migration fixture FAILED: ' . $case ); }
	}

	$wpdb = new MigrationFakeDb();
	$wpdb->switch_blog( 1 );
	$mail = [
		'active.template' => [ 'subject' => 'Mail A', 'project' => [ 'root' => [ 'type' => 'mail.root' ] ], 'base_fingerprint' => 'a', 'modified_at' => '2026-10-01' ],
		'disabled.provider-template' => [ 'subject' => 'Mail B', 'project' => [ 'root' => [ 'type' => 'mail.root' ] ], 'base_fingerprint' => 'b' ],
	];
	$branding = \CoreBlueprint\Core\Reports\ReportBranding::settings_defaults();
	$composer = \CoreBlueprint\Core\Reports\Composer\MaintenanceTemplate::defaults();
	$settings = [ 'reports' => [ 'branding' => $branding, 'composer' => [ 'maintenance' => $composer ], 'retention_days' => 999,
		'email_alerts' => [ 'generation_failed' => false ] ], 'other-secret' => 'stay in settings' ];
	$wpdb->put_option( 'cb_core_mail_template_overrides', $mail );
	$wpdb->put_option( 'cb_core_settings', $settings, 'yes' );
	migration_check( 'missing' === Migration::freshness()['status'], 'no preparatory marker' );
	$initial = MigrationInventory::inspect();
	migration_check( 'ok' === $initial['status'] && count( $initial['documents'] ) === 3, 'all literal Mail providers and report captured' );
	migration_check( 'prepared' === Migration::prepare()['status'], 'atomic prepare PASS' );
	migration_check( 'prepared' === Migration::prepare()['status'], 'idempotent prepare PASS' );
	migration_check( ! isset( $wpdb->options_by_site['wp_options']['cb_core_design_docs_cutover'] ), 'no active cutover marker' );
	migration_check( 'found' === MigrationBackup::read_verified()['status'], 'raw recovery material verified' );
	$recovery = MigrationBackup::read_verified()['source'];
	migration_check( $recovery['cb_core_mail_template_overrides']['option_value'] === serialize( $mail ), 'exact source bytes recovered' );
	migration_check( $recovery['cb_core_settings']['autoload'] === 'yes', 'source autoload preserved' );
	migration_check( 'no' === $wpdb->options_by_site['wp_options'][MigrationBackup::OPTION]['autoload'], 'recovery not autoloaded' );
	migration_check( $settings === unserialize( $wpdb->options_by_site['wp_options']['cb_core_settings']['option_value'] ), 'unrelated settings retained' );
	$docs = $wpdb->docs_by_site['wp_cb_design_documents'];
	migration_check( count( $docs ) === 3, 'full prepared target set' );
	migration_check( 'found' === Repository::get( Repository::MAIL, 'disabled.provider-template' )['status'], 'inactive provider preserved' );

	// A2.3 must reject old prepared data if any legacy writer changed source.
	$mail['active.template']['subject'] = 'NEW LEGACY CONTENT';
	$wpdb->put_option( 'cb_core_mail_template_overrides', $mail );
	migration_check( 'source_changed' === Migration::freshness()['status'], 'freshness detects stale source' );
	migration_check( 'source_changed' === Migration::prepare()['status'], 'prepare cannot silently reconcile stale marker' );
	migration_check( $docs === $wpdb->docs_by_site['wp_cb_design_documents'], 'stale source does not mutate target' );

	// Distinct source-only site: rollback after the second document insert.
	$wpdb->switch_blog( 2 );
	$wpdb->put_option( 'cb_core_mail_template_overrides', $mail );
	$wpdb->put_option( 'cb_core_settings', $settings );
	Repository::$fails_after = 2;
	migration_check( 'error' === Migration::prepare()['status'], 'interrupted insert returns failure' );
	migration_check( ! isset( $wpdb->docs_by_site['wp_2_cb_design_documents'] ), 'failed transaction rollback' );
	migration_check( ! isset( $wpdb->options_by_site['wp_2_options'][Migration::PREPARED_OPTION] ), 'no false prepared marker' );
	migration_check( isset( $wpdb->options_by_site['wp_2_options'][MigrationBackup::OPTION] ), 'recovery survives abort' );
	migration_check( 'prepared' === Migration::prepare()['status'], 'retry after rollback using exact backup' );
	migration_check( 'prepared' === Migration::freshness()['status'], 'site 2 independent' );
	$wpdb->docs_by_site['wp_2_cb_design_documents'][hash( 'sha256', Repository::MAIL . "\0active.template" )]['document']['subject'] = 'tampered';
	migration_check( 'target_changed' === Migration::freshness()['status'], 'full target readback detects tampering' );

	$wpdb->switch_blog( 3 );
	$wpdb->put_option( 'cb_core_settings', $settings );
	$wpdb->put_option( 'cb_core_mail_template_overrides', [ 'invalid/provider' => $mail['active.template'] ] );
	migration_check( 'invalid_source' === MigrationInventory::inspect()['status'], 'invalid literal ID blocks' );
	$wpdb->put_option( 'cb_core_mail_template_overrides', $mail );
	$bad_settings = $settings;
	$bad_settings['reports']['branding']['provider_name'] = str_repeat( 'X', 121 );
	$wpdb->put_option( 'cb_core_settings', $bad_settings );
	migration_check( 'invalid_source' === MigrationInventory::inspect()['status'], 'truncating Reports branding blocks' );
	$bad_settings = $settings;
	$bad_settings['reports']['composer']['maintenance']['blocks'][] = [ 'id' => 'missing', 'type' => 'unknown' ];
	$wpdb->put_option( 'cb_core_settings', $bad_settings );
	migration_check( 'invalid_source' === MigrationInventory::inspect()['status'], 'normalizer-dropping block blocks' );

	$wpdb->switch_blog( 4 );
	$wpdb->put_option( 'cb_core_mail_template_overrides', $mail );
	$wpdb->put_option( 'cb_core_settings', $settings );
	$wpdb->after_start = static function ( MigrationFakeDb $db ) use ( $mail ): void {
		$changed = $mail;
		$changed['active.template']['subject'] = 'raced';
		$db->put_option( 'cb_core_mail_template_overrides', $changed );
	};
	migration_check( 'source_changed' === Migration::prepare()['status'], 'source changed between backup and transaction' );
	migration_check( ! isset( $wpdb->options_by_site['wp_4_options'][Migration::PREPARED_OPTION] ), 'raced transaction not accepted' );

	$wpdb->switch_blog( 5 );
	$wpdb->put_option( 'cb_core_mail_template_overrides', $mail );
	$wpdb->put_option( 'cb_core_settings', $settings );
	$wpdb->fail_marker_insert = true;
	migration_check( 'uncertain' === Migration::prepare()['status'], 'marker insertion failure not acknowledged' );
	migration_check( ! isset( $wpdb->docs_by_site['wp_5_cb_design_documents'] ), 'marker failure rolls back all targets' );
	$wpdb->fail_marker_insert = false;

	$wpdb->switch_blog( 6 );
	migration_check( 'prepared' === Migration::prepare()['status'], 'fresh installation default-only preparation' );
	migration_check( count( $wpdb->docs_by_site['wp_6_cb_design_documents'] ) === 1, 'new site seeds only Reports singleton' );
	migration_check( 'prepared' === Migration::freshness()['status'], 'fresh site verified' );


	$wpdb->switch_blog( 7 );
	$wpdb->put_option( 'cb_core_mail_template_overrides', $mail );
	$wpdb->put_option( 'cb_core_settings', $settings );
	$wpdb->docs_by_site['wp_7_cb_design_documents'][hash( 'sha256', Repository::MAIL . "\0active.template" )] = [
		'identity_hash' => hash( 'sha256', Repository::MAIL . "\0active.template" ),
		'type' => Repository::MAIL, 'key' => 'active.template',
		'revision' => '9', 'document' => [ 'subject' => 'unowned', 'project' => [ 'root' => [] ] ],
	];
	migration_check( 'conflict' === Migration::prepare()['status'], 'unexpected existing target rejected' );
	migration_check( ! isset( $wpdb->options_by_site['wp_7_options'][Migration::PREPARED_OPTION] ), 'conflicting target never records prepared' );

	$wpdb->switch_blog( 8 );
	$wpdb->put_option( 'cb_core_mail_template_overrides', $mail );
	$wpdb->put_option( 'cb_core_settings', $settings );
	$inventory8 = MigrationInventory::inspect();
	migration_check( 'ready' === MigrationBackup::ensure( $inventory8 )['status'], 'backup creation' );
	$wpdb->options_by_site['wp_8_options'][MigrationBackup::OPTION]['option_value'] .= 'TAMPER';
	migration_check( 'corrupt' === MigrationBackup::read_verified()['status'], 'tampered snapshot rejected' );
	migration_check( 'corrupt' === Migration::prepare()['status'], 'corrupt backup blocks prepare' );

	$wpdb->switch_blog( 9 );
	$wpdb->put_option( 'cb_core_mail_template_overrides', $mail );
	$wpdb->put_option( 'cb_core_settings', $settings );
	$wpdb->options_engine = 'MyISAM';
	migration_check( 'unavailable' === MigrationInventory::inspect()['status'], 'nontransactional legacy options blocked' );
	$wpdb->options_engine = 'InnoDB';
	$wpdb->unique_option_name = false;
	migration_check( 'unavailable' === MigrationInventory::inspect()['status'], 'broken option identity blocked' );
	$wpdb->unique_option_name = true;

	$wpdb->switch_blog( 10 );
	$wpdb->put_option( 'cb_core_mail_template_overrides', $mail );
	$wpdb->put_option( 'cb_core_settings', $settings );
	$wpdb->fail_commit = true;
	migration_check( 'uncertain' === Migration::prepare()['status'], 'unknown commit outcome never reports PASS' );
	$wpdb->fail_commit = false;
	migration_check( 'prepared' === Migration::prepare()['status'], 'later verified full receipt can reconcile uncertain commit' );

	echo "Base Design Document source-only migration regression: PASS\n";
}
