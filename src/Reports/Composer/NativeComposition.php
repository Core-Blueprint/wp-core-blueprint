<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Reports\Composer;

use CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter;
use CoreBlueprint\Core\Reports\ReportBranding;

defined( 'ABSPATH' ) || exit;

/**
 * Lossless native StableSchemaV1 Reports contract, shared by PDF and preview.
 * A required server-side Save-boundary hook must invoke valid_document().
 * Never normalize, backfill, or silently migrate untrusted project JSON.
 */
final class NativeComposition {
	private const SINGLETONS = [
		'reports.header', 'reports.status', 'reports.kpis', 'reports.current-state',
		'reports.activity', 'reports.summary', 'reports.notes', 'reports.footer',
	];
	private const SWITCHES = [ 'on', 'off' ];
	private const MAX_NODES = 256;

	public static function valid_document( mixed $document ): bool {
		try {
			self::assert_document( $document );
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** @throws \DomainException On unsupported/corrupt native Reports documents. */
	public static function assert_document( mixed $document ): void {
		if ( ! self::exact( $document, [ 'project', 'fields' ] ) ) {
			self::invalid();
		}
		self::assert_project( $document['project'] );
		self::assert_fields( $document['fields'] );
	}

	public static function assert_project( mixed $project ): void {
		if ( ! self::exact( $project, [ 'schema_version', 'design_type', 'root' ] )
			|| 1 !== $project['schema_version']
			|| PlatformAdapter::TYPE !== $project['design_type'] ) {
			self::invalid();
		}
		if ( ! is_array( $project['root'] )
			|| ( $project['root']['type'] ?? null ) !== PlatformAdapter::TYPE ) {
			self::invalid();
		}
		$seen = [];
		$count = 0;
		self::visit( $project['root'], null, 0, $seen, $count );
		$children = $project['root']['children'];
		if ( count( $children ) < 2
			|| 'reports.header' !== $children[0]['type']
			|| 'reports.footer' !== $children[ count( $children ) - 1 ]['type'] ) {
			self::invalid();
		}
	}

	public static function assert_fields( mixed $fields ): void {
		$definitions = PlatformAdapter::branding_definitions();
		if ( ! self::exact( $fields, array_keys( $definitions ) ) ) {
			self::invalid();
		}
		foreach ( $definitions as $name => $definition ) {
			if ( ! is_string( $fields[ $name ] )
				|| ! self::bounded( $fields[ $name ], $definition['max'] )
				|| ( isset( $definition['enum'] )
					&& ! in_array( $fields[ $name ], $definition['enum'], true ) ) ) {
				self::invalid();
			}
		}
		// Enums are checked above against the SAME declarations published
		// by PlatformAdapter to Registry/StableSchemaV1.
		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/D', $fields['accent_color'] )
			|| ! preg_match( '/^(0|[1-9][0-9]{0,10})$/D', $fields['logo_attachment_id'] )
			|| (string) (int) $fields['logo_attachment_id'] !== $fields['logo_attachment_id']
			|| ! preg_match( '/^(?:[5-9]|[1-3][0-9]|40)$/D', $fields['page_margin_mm'] )
			|| ! preg_match( '/^(?:[0-9]|1[0-9]|20)$/D', $fields['section_spacing_mm'] ) ) {
			self::invalid();
		}
		$attachment = (int) $fields['logo_attachment_id'];
		if ( $attachment > 0 && ! ReportBranding::is_supported_logo_attachment( $attachment ) ) {
			self::invalid();
		}
	}

	/** Native fields mapped only to existing typed Flow branding inputs. */
	public static function flow_branding( array $fields ): array {
		self::assert_fields( $fields );
		return [
			'logo_attachment_id' => (int) $fields['logo_attachment_id'],
			'show_logo' => 'on' === $fields['show_logo'],
			'provider_name' => $fields['provider_name'],
			'provider_contact' => $fields['provider_contact'],
			'accent_color' => $fields['accent_color'],
			'surface_style' => $fields['surface_style'],
			'density' => $fields['density'],
			'corner_style' => $fields['corner_style'],
			'text_scale' => $fields['text_scale'],
		];
	}

	/** Every dynamic block can only consume its own typed node properties. */
	public static function setting( array $properties, string $key ): bool {
		return ( $properties[ $key ] ?? null ) === 'on';
	}

	private static function visit( mixed $node, ?string $parent, int $depth, array &$seen, int &$count ): void {
		$definitions = PlatformAdapter::node_definitions();
		if ( ! self::exact( $node, [ 'type', 'provider', 'properties', 'children' ] )
			|| ! is_string( $node['type'] )
			|| ! isset( $definitions[ $node['type'] ] )
			|| PlatformAdapter::PROVIDER !== $node['provider']
			|| $depth > 16 || ++$count > self::MAX_NODES
			|| ! is_array( $node['children'] ) || ! array_is_list( $node['children'] ) ) {
			self::invalid();
		}
		$type = $node['type'];
		$definition = $definitions[ $type ];
		if ( null !== $parent && ! in_array( $parent, $definition['parents'], true ) ) {
			self::invalid();
		}
		if ( in_array( $type, self::SINGLETONS, true ) ) {
			if ( PlatformAdapter::TYPE !== $parent || isset( $seen[ $type ] ) ) {
				self::invalid();
			}
			$seen[ $type ] = true;
		}
		$properties = $definition['properties'];
		if ( ! self::exact( $node['properties'], array_keys( $properties ) ) ) {
			self::invalid();
		}
		foreach ( $properties as $key => $schema ) {
			$value = $node['properties'][ $key ];
			if ( ! is_string( $value ) || ! self::bounded( $value, (int) $schema['maxLength'] ) ) {
				self::invalid();
			}
			if ( in_array( $key, [
				'enabled','showIntro','showNotes','showPageNumber','showSiteUrl',
				'showMetadata','showDetails','showHeading','showSecurity','showBackups',
			], true ) && ! in_array( $value, self::SWITCHES, true ) ) {
				self::invalid();
			}
		}
		if ( ! in_array( $type, [ PlatformAdapter::TYPE, 'reports.section' ], true )
			&& [] !== $node['children'] ) {
			self::invalid();
		}
		foreach ( $node['children'] as $child ) {
			self::visit( $child, $type, $depth + 1, $seen, $count );
		}
	}

	private static function bounded( string $value, int $limit ): bool {
		return mb_strlen( $value, 'UTF-8' ) <= $limit;
	}

	private static function exact( mixed $value, array $keys ): bool {
		if ( ! is_array( $value ) || array_is_list( $value ) || count( $value ) !== count( $keys ) ) {
			return false;
		}
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				return false;
			}
		}
		return true;
	}

	private static function invalid(): never {
		throw new \DomainException( 'SCHEMA_DOMAIN_INVALID' );
	}

	private function __construct() {}
}
