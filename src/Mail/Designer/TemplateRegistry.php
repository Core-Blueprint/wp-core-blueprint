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

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Editor\PlatformTransport;

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

		// Native Platform v1 owns the only Mail component registry. The earlier
		// component/filter adapter is not activated by this template catalog.
		WordPressTemplates::register();
		/** Fires once so extensions can register Mail Designer templates. */
		do_action( 'core_blueprint_register_mail_templates' );
	}

	/**
	 * Register a provider-owned native v1 default. WordPress and extensions
	 * use the exact same document contract; no legacy project-only fallback.
	 *
	 * Provider namespaces own their resource IDs (e.g. bookings.*). The
	 * wordpress.* namespace is reserved for Base's built-in catalog.
	 *
	 * @param array{id:string,provider:string,group:string,label:string,description?:string,document:array<string,mixed>} $definition
	 */
	public static function register( array $definition ): bool {
		$id = $definition['id'] ?? null;
		$provider = $definition['provider'] ?? null;
		$group = $definition['group'] ?? null;
		$label = $definition['label'] ?? null;
		$description = $definition['description'] ?? '';
		$document = $definition['document'] ?? null;

		if ( ! is_string( $id ) || ! Repository::valid_identity( Repository::MAIL, $id )
			|| ! is_string( $provider ) || ! preg_match( '/^[a-z][a-z0-9_-]*$/D', $provider )
			|| ! is_string( $group ) || ! preg_match( '/^[a-z][a-z0-9_-]*$/D', $group )
			|| ! is_string( $label ) || '' === trim( $label )
			|| ! is_string( $description )
			|| ! is_array( $document )
			|| isset( self::$definitions[ $id ] ) ) {
			return false;
		}

		$namespace = explode( '.', $id, 2 )[0];
		if ( 'wordpress' === $namespace ) {
			// Reserve both ID and exact built-in wording against early hook
			// squatters. No third-party wordpress.* resource authorization.
			if ( 'core' !== $provider || 'wordpress' !== $group
				|| $document !== WordPressTemplates::default_document( $id ) ) return false;
		} elseif ( $namespace !== $provider ) {
			return false;
		}

		// Exactly the same PHP native-v1 admission as a persisted Mail row.
		// A malformed default is refused at registration, not normalized.
		if ( ! PlatformTransport::valid_native_document( Repository::MAIL, $document ) ) {
			return false;
		}

		self::$definitions[ $id ] = [
			'id'          => $id,
			'provider'    => $provider,
			'group'       => $group,
			'label'       => sanitize_text_field( $label ),
			'description' => sanitize_text_field( $description ),
			'document'    => $document,
			// Compatibility read fields; document remains the sole default.
			'project'     => $document['project'],
			'fields'      => $document['fields'],
			'subject'     => $document['fields']['subject'],
			'preheader'   => $document['fields']['preheader'],
		];
		return true;
	}

	/** Read-only default used by BOTH editor context and runtime renderer. */
	public static function default_document( string $id ): ?array {
		$definition = self::get( $id );
		return is_array( $definition ) ? $definition['document'] : null;
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
