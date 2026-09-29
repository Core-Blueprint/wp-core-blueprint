<?php
declare(strict_types=1);
/**
 * Internal Core Setup v1 check registry.
 *
 * V1 is intentionally Base-owned. There is no extension filter here: an
 * extension-contributed setup contract is explicitly post-v1 scope.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup;

use CB\Core\Setup\Checks\EnvironmentIndexingProtectionCheck;
use CB\Core\Setup\Checks\LoginShieldCheck;
use CB\Core\Setup\Checks\MailDeliveryStrategyCheck;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** @return array<string,CheckInterface> */
	public static function all(): array {
		$checks = [
			new EnvironmentIndexingProtectionCheck(),
			new LoginShieldCheck(),
			new MailDeliveryStrategyCheck(),
		];

		$out = [];
		foreach ( $checks as $check ) {
			self::assert_valid( $check );
			if ( isset( $out[ $check->id() ] ) ) {
				throw new \LogicException( 'Duplicate Core Setup check ID: ' . $check->id() );
			}
			$out[ $check->id() ] = $check;
		}

		return $out;
	}

	public static function get( string $id ): ?CheckInterface {
		return self::all()[ $id ] ?? null;
	}

	/** @return array<string,CheckInterface> */
	public static function visible(): array {
		$out = [];
		foreach ( self::all() as $id => $check ) {
			if ( current_user_can( $check->capability() ) ) {
				$out[ $id ] = $check;
			}
		}
		return $out;
	}

	/** @param array<string,CheckInterface>|null $checks @return array<string,array<string,CheckInterface>> */
	public static function sections( ?array $checks = null ): array {
		$checks ??= self::all();
		$sections = [];
		foreach ( $checks as $id => $check ) {
			$sections[ $check->section() ][ $id ] = $check;
		}
		return $sections;
	}

	private static function assert_valid( CheckInterface $check ): void {
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $check->id() ) ) {
			throw new \LogicException( 'Invalid Core Setup check ID.' );
		}
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $check->section() ) ) {
			throw new \LogicException( 'Invalid Core Setup section ID.' );
		}
		if ( ! in_array( $check->kind(), CheckInterface::KINDS, true ) ) {
			throw new \LogicException( 'Invalid Core Setup check kind.' );
		}
		if ( '' === $check->label() || '' === sanitize_key( $check->capability() ) ) {
			throw new \LogicException( 'Incomplete Core Setup check definition.' );
		}
	}

	private function __construct() {}
}
