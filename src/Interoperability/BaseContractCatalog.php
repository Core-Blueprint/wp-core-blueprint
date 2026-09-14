<?php
declare(strict_types=1);
/**
 * Internal read-only catalog of Base-owned interoperability contracts.
 *
 * Extensions cannot mutate or contribute to this catalog. Public extensions use
 * the normal Interoperability registration lifecycle for extension-owned
 * contracts and provider implementations.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Interoperability;

use CB\Core\DataExchange\Foundation as DataExchangeFoundation;
use CB\Core\Forms\Foundation as FormsFoundation;

defined( 'ABSPATH' ) || exit;

/** @internal Base bootstrap infrastructure. */
final class BaseContractCatalog {

	/**
	 * @return list<array{owner:string,id:string,version:string,label:string,description:string,interface:string}>
	 */
	public static function definitions(): array {
		return [
			FormsFoundation::contract_definition(),
			DataExchangeFoundation::contract_definition(),
		];
	}
}
