<?php
declare(strict_types=1);

namespace CB\Core\Reports\Composer;

use CB\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class MaintenanceTemplate {
	public const SCHEMA_VERSION = 1;

	/** @return array{schema_version:int,blocks:list<array{id:string,type:string,enabled:bool,settings:array<string,mixed>}>} */
	public static function defaults(): array {
		return [
			'schema_version' => self::SCHEMA_VERSION,
			'blocks'         => BlockCatalog::default_blocks(),
		];
	}

	/** @return array{schema_version:int,blocks:list<array{id:string,type:string,enabled:bool,settings:array<string,mixed>}>} */
	public static function current(): array {
		$settings = Settings::get();
		$raw      = $settings['reports']['composer']['maintenance'] ?? [];
		return self::normalize( is_array( $raw ) ? $raw : [] );
	}

	/**
	 * Tolerant read-normalizer for the bounded v1 singleton block document.
	 * Unknown/duplicate types are dropped; missing canonical blocks are healed.
	 * Header/footer are always enabled and anchored first/last.
	 *
	 * @param array<string,mixed> $input
	 * @return array{schema_version:int,blocks:list<array{id:string,type:string,enabled:bool,settings:array<string,mixed>}>}
	 */
	public static function normalize( array $input ): array {
		$defaults     = BlockCatalog::default_blocks();
		$default_by   = [];
		$known        = BlockCatalog::definitions();
		$seen         = [];
		$ordered      = [];
		$input_blocks = is_array( $input['blocks'] ?? null ) ? $input['blocks'] : [];

		foreach ( $defaults as $block ) {
			$default_by[ $block['type'] ] = $block;
		}

		foreach ( $input_blocks as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$type = sanitize_key( (string) ( $raw['type'] ?? '' ) );
			if ( '' === $type || ! isset( $known[ $type ] ) || isset( $seen[ $type ] ) ) {
				continue;
			}
			$seen[ $type ] = true;
			$ordered[] = [
				'id'       => $type,
				'type'     => $type,
				'enabled'  => ! empty( $known[ $type ]['required'] ) ? true : (bool) ( $raw['enabled'] ?? true ),
				'settings' => [],
			];
		}

		foreach ( $defaults as $block ) {
			if ( ! isset( $seen[ $block['type'] ] ) ) {
				$ordered[] = $block;
			}
		}

		$header = $default_by[ BlockCatalog::HEADER ];
		$footer = $default_by[ BlockCatalog::FOOTER ];
		$middle = [];

		foreach ( $ordered as $block ) {
			if ( BlockCatalog::HEADER === $block['type'] ) {
				$header = $block;
				continue;
			}
			if ( BlockCatalog::FOOTER === $block['type'] ) {
				$footer = $block;
				continue;
			}
			$middle[] = $block;
		}

		$header['enabled'] = true;
		$footer['enabled'] = true;

		return [
			'schema_version' => self::SCHEMA_VERSION,
			'blocks'         => array_values( array_merge( [ $header ], $middle, [ $footer ] ) ),
		];
	}

	private function __construct() {}
}
