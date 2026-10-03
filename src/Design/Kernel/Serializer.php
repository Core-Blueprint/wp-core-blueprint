<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

final class Serializer {
	private const JSON_DEPTH = 128;

	public function __construct( private readonly SchemaValidator $validator ) {}

	public function decode( string $json, bool $allow_experimental = false ): DesignProject {
		try {
			$payload = json_decode( $json, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			$diagnostics = new Diagnostics();
			$diagnostics->error( 'json.invalid', 'Design JSON could not be decoded.', 'design' );
			throw new ValidationException( $diagnostics );
		}

		if ( ! is_array( $payload ) || array_is_list( $payload ) ) {
			$diagnostics = new Diagnostics();
			$diagnostics->error( 'schema.invalid_envelope', 'Design JSON must decode to an object.', 'design' );
			throw new ValidationException( $diagnostics );
		}

		$diagnostics = $this->validator->validate( $payload, $allow_experimental );
		if ( $diagnostics->has_errors() ) {
			throw new ValidationException( $diagnostics );
		}

		return new DesignProject(
			(int) $payload['schema_version'],
			(string) $payload['design_type'],
			(array) $payload['root']
		);
	}

	public function encode( DesignProject $project ): string {
		$payload = $project->to_array();
		$allow_experimental = 0 === $project->schema_version();
		$diagnostics = $this->validator->validate( $payload, $allow_experimental );
		if ( $diagnostics->has_errors() ) {
			throw new ValidationException( $diagnostics );
		}

		$payload['root'] = $this->normalize_node_for_json( $payload['root'] );
		return json_encode(
			$payload,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
			self::JSON_DEPTH
		);
	}

	/** @param array<string,mixed> $node */
	private function normalize_node_for_json( array $node ): object|array {
		if ( [] === $node ) {
			return (object) [];
		}
		$normalized = $node;
		if ( isset( $normalized['properties'] ) && [] === $normalized['properties'] ) {
			$normalized['properties'] = (object) [];
		}
		if ( isset( $normalized['children'] ) && is_array( $normalized['children'] ) ) {
			$normalized['children'] = array_map(
				fn ( mixed $child ): mixed => is_array( $child ) ? $this->normalize_node_for_json( $child ) : $child,
				$normalized['children']
			);
		}
		return $normalized;
	}
}
