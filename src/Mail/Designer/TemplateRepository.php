<?php
declare(strict_types=1);
/**
 * Persistence boundary for user-customized Mail Designer templates.
 *
 * Provider defaults are never copied globally. Only explicit overrides are
 * stored, together with the provider-default fingerprint they were based on.
 * Unknown overrides remain untouched while a provider is temporarily inactive.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Design\Profile\Mail\Validator;
use CoreBlueprint\Core\Design\Document\Authority;
use CoreBlueprint\Core\Design\Document\Repository;

defined( 'ABSPATH' ) || exit;

/** Typed integrity error, never a reason to silently render provider defaults. */
final class DocumentUnavailable extends \RuntimeException {
	public function __construct( public readonly string $reason ) {
		parent::__construct( 'Mail Designer canonical document is unavailable.' );
	}
}

final class TemplateRepository {
	public const OPTION = 'cb_core_mail_template_overrides';

	/** @return array<string,mixed>|null */
	public static function get( string $template_id ): ?array {
		$definition = TemplateRegistry::get( $template_id );
		if ( null === $definition ) {
			return null;
		}

		$authority = Authority::inspect();
		if ( 'active' === $authority['status'] ) {
			$resolved = DocumentAdapter::read( $template_id, $definition, $authority );
			if ( ! in_array( $resolved['status'], [ 'found', 'default' ], true ) ) {
				throw new DocumentUnavailable( (string) $resolved['status'] );
			}
			return array_merge( $definition, $resolved['document'], [
				'revision' => $resolved['revision'],
				'authority' => 'active',
			] );
		}
		if ( ! in_array( $authority['status'], [ 'legacy', 'prepared' ], true ) ) {
			throw new DocumentUnavailable( 'blocked' );
		}
		$overrides = self::overrides();
		$override = is_array( $overrides[ $template_id ] ?? null ) ? $overrides[ $template_id ] : [];
		$project = is_array( $override['project'] ?? null ) ? $override['project'] : $definition['project'];
		$subject = isset( $override['subject'] ) && is_string( $override['subject'] ) ? $override['subject'] : (string) $definition['subject'];

		return array_merge( $definition, [
			'subject'          => $subject,
			'project'          => $project,
			'customized'       => [] !== $override,
			'modified_at'      => isset( $override['modified_at'] ) ? (string) $override['modified_at'] : '',
			'base_fingerprint' => isset( $override['base_fingerprint'] ) ? (string) $override['base_fingerprint'] : self::fingerprint( $definition ),
		'revision' => 'legacy',
		'authority' => $authority['status'],
		]);
	}


	/** Mutation route with authoritative revisioned readback for active sites. */
	public static function mutate(
		string $template_id, string $expected, string $subject, array $project
	): array {
		$definition = TemplateRegistry::get( $template_id );
		if ( null === $definition ) {
			return [ 'status' => 'invalid' ];
		}
		$authority = Authority::inspect();
		if ( 'active' === $authority['status'] ) {
			return DocumentAdapter::save( $template_id, $definition, $authority, $expected, $subject, $project );
		}
		if ( ! in_array( $authority['status'], [ 'legacy', 'prepared' ], true ) ) {
			return [ 'status' => 'blocked' ];
		}
		if ( 'legacy' !== $expected || ! self::save_legacy( $template_id, $subject, $project ) ) {
			return [ 'status' => 'invalid' ];
		}
		$read = self::get( $template_id );
		if ( null === $read || $subject !== $read['subject'] || $project !== $read['project'] ) {
			return [ 'status' => 'uncertain' ];
		}
		return [ 'status' => 'saved', 'revision' => 'legacy', 'document' => [
			'subject' => $read['subject'], 'project' => $read['project'],
		] ];
	}

	public static function reset_revisioned( string $template_id, string $expected ): array {
		$definition = TemplateRegistry::get( $template_id );
		if ( null === $definition ) {
			return [ 'status' => 'invalid' ];
		}
		$authority = Authority::inspect();
		if ( 'active' === $authority['status'] ) {
			return DocumentAdapter::reset( $template_id, $definition, $authority, $expected );
		}
		if ( ! in_array( $authority['status'], [ 'legacy', 'prepared' ], true ) ) {
			return [ 'status' => 'blocked' ];
		}
		if ( 'legacy' !== $expected || ! self::reset_legacy( $template_id ) ) {
			return [ 'status' => 'invalid' ];
		}
		$read = self::get( $template_id );
		return is_array( $read ) && $read['subject'] === $definition['subject']
			&& $read['project'] === $definition['project']
			? [ 'status' => 'saved', 'revision' => 'legacy',
				'document' => [ 'subject' => $read['subject'], 'project' => $read['project'] ] ]
			: [ 'status' => 'uncertain' ];
	}

	/** @param array<string,mixed> $project */
	public static function save( string $template_id, string $subject, array $project ): bool {
		if ( ! in_array( Authority::inspect()['status'], [ 'legacy', 'prepared' ], true ) ) {
			return false;
		}
		return self::save_legacy( $template_id, $subject, $project );
	}

	/** Never called for an active canonical site. */
	private static function save_legacy( string $template_id, string $subject, array $project ): bool {
		$definition = TemplateRegistry::get( $template_id );
		if ( null === $definition ) {
			return false;
		}

		$diagnostics = ( new Validator() )->validate( $project );
		if ( $diagnostics->has_errors() ) {
			return false;
		}

		$subject = sanitize_text_field( $subject );
		if ( '' === $subject ) {
			return false;
		}

		$overrides = self::overrides();
		$entry = [
			'subject'          => $subject,
			'project'          => $project,
			'base_fingerprint' => self::fingerprint( $definition ),
			'modified_at'      => gmdate( 'Y-m-d H:i:s' ),
		];
		$overrides[ $template_id ] = $entry;
		$changed = update_option( self::OPTION, $overrides, false );
		if ( $changed ) {
			return true;
		}

		// update_option() also returns false for a successful no-op. Verify the
		// persisted entry so clicking Save twice never surfaces a false failure.
		$stored = self::overrides();
		return isset( $stored[ $template_id ] ) && $entry === $stored[ $template_id ];
	}

	public static function reset( string $template_id ): bool {
		if ( ! in_array( Authority::inspect()['status'], [ 'legacy', 'prepared' ], true ) ) {
			return false;
		}
		return self::reset_legacy( $template_id );
	}

	private static function reset_legacy( string $template_id ): bool {
		if ( null === TemplateRegistry::get( $template_id ) ) {
			return false;
		}
		$overrides = self::overrides();
		if ( ! isset( $overrides[ $template_id ] ) ) {
			return true;
		}
		unset( $overrides[ $template_id ] );
		$changed = update_option( self::OPTION, $overrides, false );
		return $changed || ! isset( self::overrides()[ $template_id ] );
	}

	/** @return array<string,array<string,mixed>> */
	public static function overrides(): array {
		$stored = get_option( self::OPTION, [] );
		return is_array( $stored ) ? $stored : [];
	}

	/** @param array<string,mixed> $definition */
	private static function fingerprint( array $definition ): string {
		$payload = [
			'subject' => (string) ( $definition['subject'] ?? '' ),
			'project' => is_array( $definition['project'] ?? null ) ? $definition['project'] : [],
		];
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	private function __construct() {}
}
