<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Reports\Composer;

defined( 'ABSPATH' ) || exit;

final class BlockCatalog {
	public const HEADER        = 'header';
	public const STATUS        = 'status';
	public const KPIS          = 'kpis';
	public const CURRENT_STATE = 'current_state';
	public const ACTIVITY      = 'activity';
	public const SUMMARY       = 'summary';
	public const NOTES         = 'notes';
	public const FOOTER        = 'footer';

	/** @return array<string,array{required:bool,locked_position:string,source:string,singleton:bool,settings:array<string,bool>}> */
	public static function definitions(): array {
		return [
			self::HEADER => [
				'required'        => true,
				'locked_position' => 'first',
				'source'          => 'report.meta',
				'singleton'       => true,
				'settings'        => [
					'show_site_url' => true,
					'show_metadata' => true,
				],
			],
			self::STATUS => [
				'required'        => false,
				'locked_position' => '',
				'source'          => 'maintenance.status',
				'singleton'       => true,
				'settings'        => [
					'show_details' => true,
				],
			],
			self::KPIS => [
				'required'        => false,
				'locked_position' => '',
				'source'          => 'maintenance.kpis',
				'singleton'       => true,
				'settings'        => [
					'show_details' => true,
				],
			],
			self::CURRENT_STATE => [
				'required'        => false,
				'locked_position' => '',
				'source'          => 'maintenance.site_state',
				'singleton'       => true,
				'settings'        => [
					'show_notes' => true,
				],
			],
			self::ACTIVITY => [
				'required'        => false,
				'locked_position' => '',
				'source'          => 'maintenance.sections',
				'singleton'       => true,
				'settings'        => [
					'show_intro' => true,
				],
			],
			self::SUMMARY => [
				'required'        => false,
				'locked_position' => '',
				'source'          => 'maintenance.security+maintenance.backups',
				'singleton'       => true,
				'settings'        => [
					'show_security' => true,
					'show_backups'  => true,
				],
			],
			self::NOTES => [
				'required'        => false,
				'locked_position' => '',
				'source'          => 'maintenance.notes',
				'singleton'       => true,
				'settings'        => [
					'show_heading' => true,
				],
			],
			self::FOOTER => [
				'required'        => true,
				'locked_position' => 'last',
				'source'          => 'report.meta',
				'singleton'       => true,
				'settings'        => [
					'show_page_number' => true,
				],
			],
		];
	}

	/** @return string[] */
	public static function types(): array {
		return array_keys( self::definitions() );
	}

	public static function has( string $type ): bool {
		return isset( self::definitions()[ $type ] );
	}

	/** @return array<string,bool> */
	public static function settings_for( string $type ): array {
		$definition = self::definitions()[ $type ] ?? null;
		return is_array( $definition ) ? $definition['settings'] : [];
	}

	/** @param array<string,mixed> $settings @return array<string,bool> */
	public static function normalize_settings( string $type, array $settings ): array {
		$defaults = self::settings_for( $type );
		$result   = [];
		foreach ( $defaults as $key => $default ) {
			$result[ $key ] = array_key_exists( $key, $settings )
				? (bool) $settings[ $key ]
				: $default;
		}
		return $result;
	}

	/** @return list<array{id:string,type:string,enabled:bool,settings:array<string,bool>}> */
	public static function default_blocks(): array {
		return array_map(
			static fn ( string $type ): array => [
				'id'       => $type,
				'type'     => $type,
				'enabled'  => true,
				'settings' => self::settings_for( $type ),
			],
			[
				self::HEADER,
				self::STATUS,
				self::KPIS,
				self::CURRENT_STATE,
				self::ACTIVITY,
				self::SUMMARY,
				self::NOTES,
				self::FOOTER,
			]
		);
	}

	private function __construct() {}
}
