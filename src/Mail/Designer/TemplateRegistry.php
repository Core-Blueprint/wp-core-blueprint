<?php
declare(strict_types=1);
/**
 * Public registry for Mail Designer template providers.
 *
 * Extensions register template definitions during
 * `core_blueprint_register_mail_templates`. Base owns editor/render/persistence; the
 * provider owns template semantics and runtime context.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Design\Profile\Mail\Validator;

defined( 'ABSPATH' ) || exit;

final class TemplateRegistry {
	/** @var array<string,array<string,mixed>> */
	private static array $definitions = [];
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Component providers register their node type/provider pairs before any
		// extension template is validated. This lets domain plugins contribute
		// custom blocks without coupling the Design Foundation to their domain.
		ComponentRegistry::boot();
		WordPressTemplates::register();
		/** Fires once so extensions can register Mail Designer templates. */
		do_action( 'core_blueprint_register_mail_templates' );
	}

	/**
	 * @param array{id:string,provider:string,group:string,label:string,description?:string,subject:string,project:array<string,mixed>} $definition
	 */
	public static function register( array $definition ): bool {
		$id = isset( $definition['id'] ) ? trim( (string) $definition['id'] ) : '';
		$provider = isset( $definition['provider'] ) ? sanitize_key( (string) $definition['provider'] ) : '';
		$group = isset( $definition['group'] ) ? sanitize_key( (string) $definition['group'] ) : '';
		$label = isset( $definition['label'] ) ? sanitize_text_field( (string) $definition['label'] ) : '';
		$description = isset( $definition['description'] ) ? sanitize_text_field( (string) $definition['description'] ) : '';
		$subject = isset( $definition['subject'] ) ? sanitize_text_field( (string) $definition['subject'] ) : '';
		$project = $definition['project'] ?? null;

		if (
			1 !== preg_match( '/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/', $id )
			|| '' === $provider
			|| '' === $group
			|| '' === $label
			|| ! is_array( $project )
			|| isset( self::$definitions[ $id ] )
		) {
			return false;
		}

		$diagnostics = ( new Validator() )->validate( $project );
		if ( $diagnostics->has_errors() ) {
			return false;
		}

		self::$definitions[ $id ] = [
			'id'          => $id,
			'provider'    => $provider,
			'group'       => $group,
			'label'       => $label,
			'description' => $description,
			'subject'     => $subject,
			'project'     => $project,
		];
		return true;
	}

	/** @return array<string,mixed>|null */
	public static function get( string $id ): ?array {
		self::boot();
		return self::$definitions[ $id ] ?? null;
	}

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		self::boot();
		$definitions = self::$definitions;
		uasort( $definitions, static function ( array $a, array $b ): int {
			$group = strcasecmp( (string) $a['group'], (string) $b['group'] );
			return 0 !== $group ? $group : strcasecmp( (string) $a['label'], (string) $b['label'] );
		} );
		return $definitions;
	}

	/** @internal tests */
	public static function _reset_for_testing(): void {
		self::$definitions = [];
		self::$booted = false;
	}

	private function __construct() {}
}
