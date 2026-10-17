<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Document;

use CoreBlueprint\Core\Mail\Designer\TemplateRepository;
use CoreBlueprint\Core\Reports\Composer\MaintenanceTemplate;
use CoreBlueprint\Core\Reports\ReportBranding;
use CoreBlueprint\Core\Reports\ReportBrandingInput;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only primary-DB inventory. No WordPress options cache, provider lookup,
 * content logging or lossy repairs. Each invocation belongs to ONE blog.
 *
 * @internal Not a public migration or consumer API.
 */
final class MigrationInventory {
	public const SETTINGS_OPTION = 'cb_core_settings';
	private const MAX_OVERRIDES = 1024;
	private const MAX_SOURCE_BYTES = 33554432;

	/** @return array<string,mixed> */
	public static function inspect( bool $lock = false ): array {
		global $wpdb;

		if ( ! Schema::healthy() || ! self::valid_site() ) {
			return [ 'status' => 'unavailable' ];
		}

		$source = [];
		foreach ( [ TemplateRepository::OPTION, self::SETTINGS_OPTION ] as $name ) {
			$row = self::read_option( $name, $lock );
			if ( 'ok' !== $row['status'] ) {
				return [ 'status' => 'error' ];
			}
			$source[ $name ] = $row['option'];
		}
		$fingerprint = self::fingerprint( $source );
		if ( null === $fingerprint ) {
			return [ 'status' => 'invalid_source' ];
		}

		try {
			$mail = self::unpack( $source[TemplateRepository::OPTION] );
			$settings = self::unpack( $source[self::SETTINGS_OPTION] );
			if ( ! is_array( $mail ) || ! is_array( $settings ) || array_is_list( $mail ) && [] !== $mail ) {
				return [ 'status' => 'invalid_source' ];
			}
			if ( count( $mail ) > self::MAX_OVERRIDES ) {
				return [ 'status' => 'invalid_source' ];
			}
			$documents = [];
			foreach ( $mail as $id => $entry ) {
				if ( ! is_string( $id ) || ! Repository::valid_identity( Repository::MAIL, $id )
					|| ! is_array( $entry ) || array_is_list( $entry )
					|| ! isset( $entry['subject'], $entry['project'] )
					|| ! is_string( $entry['subject'] ) || '' === trim( $entry['subject'] )
					|| ! is_array( $entry['project'] ) || array_is_list( $entry['project'] ) ) {
					return [ 'status' => 'invalid_source' ];
				}
				// Preserve every unknown key and inactive-provider entry verbatim.
				if ( array_key_exists( 'customized', $entry ) && true !== $entry['customized'] ) {
					return [ 'status' => 'invalid_source' ];
				}
				$document = $entry;
				$document['customized'] = true;
				if ( ! Repository::valid_document( Repository::MAIL, $document ) ) {
					return [ 'status' => 'invalid_source' ];
				}
				$documents[Repository::MAIL . "\0" . $id] = [
					'type' => Repository::MAIL, 'key' => $id, 'document' => $document,
				];
			}

			$report = self::reports_document( $settings );
			if ( null === $report || ! Repository::valid_document( Repository::REPORTS, $report ) ) {
				return [ 'status' => 'invalid_source' ];
			}
			$documents[Repository::REPORTS . "\0maintenance"] = [
				'type' => Repository::REPORTS, 'key' => 'maintenance', 'document' => $report,
			];
			ksort( $documents, SORT_STRING );
			$target_hash = self::target_hash( $documents );
			if ( null === $target_hash ) {
				return [ 'status' => 'invalid_source' ];
			}
			return [
				'status' => 'ok',
				'blog_id' => (int) get_current_blog_id(),
				'prefix' => $wpdb->prefix,
				'options_table' => $wpdb->options,
				'source' => $source,
				'fingerprint' => $fingerprint,
				'target_hash' => $target_hash,
				'documents' => $documents,
			];
		} catch ( \Throwable $error ) {
			return [ 'status' => 'invalid_source' ];
		}
	}

	/** @return array{status:string,option?:array<string,mixed>|null} */
	public static function read_option( string $name, bool $lock = false ): array {
		global $wpdb;
		if ( ! self::valid_site() || ! in_array(
			$name, [ TemplateRepository::OPTION, self::SETTINGS_OPTION, MigrationBackup::OPTION, Migration::PREPARED_OPTION ], true
		) ) {
			return [ 'status' => 'invalid' ];
		}
		$sql = 'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s LIMIT 1';
		if ( $lock ) {
			$sql .= ' FOR UPDATE';
		}
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $wpdb->options, $name ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return '' === (string) $wpdb->last_error
				? [ 'status' => 'ok', 'option' => null ]
				: [ 'status' => 'error' ];
		}
		if ( $name !== (string) ( $row['option_name'] ?? '' )
			|| ! is_string( $row['option_value'] ?? null )
			|| ! is_string( $row['autoload'] ?? null ) ) {
			return [ 'status' => 'error' ];
		}
		return [ 'status' => 'ok', 'option' => [
			'option_name' => $name,
			'option_value' => $row['option_value'],
			'autoload' => $row['autoload'],
		] ];
	}

	/** Fingerprints cover raw serialized bytes, absence and autoload metadata. */
	public static function fingerprint( array $source ): ?string {
		global $wpdb;
		if ( ! self::valid_site() ) {
			return null;
		}
		$canonical = [ 'blog' => (int) get_current_blog_id(), 'table' => $wpdb->options, 'source' => [] ];
		foreach ( [ TemplateRepository::OPTION, self::SETTINGS_OPTION ] as $name ) {
			if ( ! array_key_exists( $name, $source ) ) {
				return null;
			}
			$row = $source[$name];
			if ( null !== $row && ( ! is_array( $row ) || ( $row['option_name'] ?? null ) !== $name
				|| ! is_string( $row['option_value'] ?? null ) || ! is_string( $row['autoload'] ?? null ) ) ) {
				return null;
			}
			$canonical['source'][$name] = null === $row ? null : [
				'autoload' => $row['autoload'], 'raw_base64' => base64_encode( $row['option_value'] ),
			];
		}
		$json = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) && strlen( $json ) <= self::MAX_SOURCE_BYTES
			? hash( 'sha256', $json ) : null;
	}

	public static function target_hash( array $documents ): ?string {
		$canonical = [];
		foreach ( $documents as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['type'], $item['key'], $item['document'] )
				|| ! Repository::valid_identity( $item['type'], $item['key'] )
				|| ! Repository::valid_document( $item['type'], $item['document'] ) ) {
				return null;
			}
			$canonical[] = [ $item['type'], $item['key'], $item['document'] ];
		}
		$json = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : null;
	}

	/** @param array<string,mixed>|null $option */
	private static function unpack( ?array $option ): mixed {
		if ( null === $option ) {
			return [];
		}
		$raw = $option['option_value'];
		// Do not instantiate objects or execute autoloaders from legacy data.
		$value = is_serialized( $raw ) ? @unserialize( $raw, [ 'allowed_classes' => false ] ) : $raw;
		if ( self::has_object( $value ) ) {
			throw new \UnexpectedValueException( 'Object-valued legacy option.' );
		}
		return $value;
	}

	private static function has_object( mixed $value, int $depth = 0 ): bool {
		// PHP serialized arrays may contain recursive references. Treat
		// excessive nesting as invalid instead of risking an unbounded walk.
		if ( $depth > 64 || is_object( $value ) || is_resource( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::has_object( $item, $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** @param array<string,mixed> $settings */
	private static function reports_document( array $settings ): ?array {
		$reports = $settings['reports'] ?? [];
		if ( ! is_array( $reports ) ) {
			return null;
		}
		$branding_raw = $reports['branding'] ?? [];
		$composer_raw = $reports['composer'] ?? [];
		if ( ! is_array( $branding_raw ) || ! is_array( $composer_raw )
			|| ( [] !== $branding_raw && array_is_list( $branding_raw ) )
			|| ( [] !== $composer_raw && array_is_list( $composer_raw ) ) ) {
			return null;
		}
		$branding = [] === $branding_raw ? ReportBranding::settings_defaults() : $branding_raw;
		$normalized_branding = ReportBrandingInput::normalize( $branding );
		if ( $branding !== $normalized_branding ) {
			return null;
		}
		foreach ( $composer_raw as $key => $value ) {
			if ( 'maintenance' !== $key ) {
				return null;
			}
		}
		$maintenance_raw = $composer_raw['maintenance'] ?? [];
		if ( ! is_array( $maintenance_raw ) ) {
			return null;
		}
		$maintenance = [] === $maintenance_raw ? MaintenanceTemplate::defaults() : $maintenance_raw;
		$normalized = MaintenanceTemplate::normalize( $maintenance );
		if ( $maintenance !== $normalized ) {
			return null;
		}
		return [ 'branding' => $branding, 'composer' => [ 'maintenance' => $maintenance ] ];
	}

	private static function valid_site(): bool {
		global $wpdb;
		return is_object( $wpdb ) && is_string( $wpdb->prefix ?? null )
			&& 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->prefix )
			&& is_string( $wpdb->options ?? null )
			&& $wpdb->options === $wpdb->prefix . 'options'
			&& (int) get_current_blog_id() >= 1;
	}

	private function __construct() {}
}
