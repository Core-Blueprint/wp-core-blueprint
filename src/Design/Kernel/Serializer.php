<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Kernel;

defined( 'ABSPATH' ) || exit;

final class Serializer {
	private const JSON_DEPTH = 128;

	public function __construct(
		private readonly SchemaValidator $validator,
		private readonly ?StableSchemaRegistry $stable_definitions = null,
		private readonly ?string $profile_id = null,
		private readonly ?string $project_schema = null
	) {}

	public function decode( string $json, bool $allow_experimental = false ): DesignProject {
		try {
			$source = json_decode( $json, false, self::JSON_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			$diagnostics = new Diagnostics();
			$diagnostics->error( 'json.invalid', 'Design JSON could not be decoded.', 'design' );
			throw new ValidationException( $diagnostics );
		}
		if ( $source instanceof \stdClass && 1 === ( $source->schema_version ?? null ) ) {
			return $this->decode_stable_source( $source );
		}

		// Explicit experimental generation 0 is preserved as a separate
		// historical route. It is never relabelled or silently migrated.
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

	/**
	 * Stable admission requires a trusted frozen schema context. The
	 * explicit method prevents v0's experimental flag from bypassing v1.
	 */
	public function decode_stable_v1(
		string $json,
		StableSchemaRegistry $definitions,
		string $profile_id,
		string $project_schema
	): DesignProject {
		try {
			$source = json_decode( $json, false, self::JSON_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			$diagnostics = new Diagnostics();
			$diagnostics->error( 'json.invalid', 'Design JSON could not be decoded.', 'design' );
			throw new ValidationException( $diagnostics );
		}
		if ( ! $source instanceof \stdClass ) {
			$diagnostics = new Diagnostics();
			$diagnostics->error( 'SCHEMA_NODE_INVALID', 'Stable JSON envelope must be an object.', 'design' );
			throw new ValidationException( $diagnostics );
		}
		return $this->decode_stable_source( $source, $definitions, $profile_id, $project_schema );
	}

	private function decode_stable_source(
		\stdClass $source,
		?StableSchemaRegistry $definitions = null,
		?string $profile_id = null,
		?string $project_schema = null
	): DesignProject {
		$definitions ??= $this->stable_definitions;
		$profile_id ??= $this->profile_id;
		$project_schema ??= $this->project_schema;
		if ( null === $definitions || null === $profile_id || null === $project_schema ) {
			throw new \LogicException( 'Stable schema v1 requires trusted frozen profile definitions.' );
		}
		$result = $this->validator->validate_stable_v1( $source, $definitions, $profile_id, $project_schema );
		if ( ! $result['valid'] ) {
			throw self::stable_validation_exception( $result );
		}
		return new DesignProject(
			1,
			$source->design_type,
			$this->node_to_array( $source->root )
		);
	}

	public function encode( DesignProject $project ): string {
		$payload = $project->to_array();
		if ( 1 === $project->schema_version() ) {
			if ( null === $this->stable_definitions || null === $this->profile_id || null === $this->project_schema ) {
				throw new \LogicException( 'Stable schema v1 requires trusted frozen profile definitions.' );
			}
			$source = (object) [
				'schema_version' => 1,
				'design_type' => $payload['design_type'],
				'root' => $this->normalize_node_for_json( $payload['root'] ),
			];
			$result = $this->validator->validate_stable_v1(
				$source, $this->stable_definitions, $this->profile_id, $this->project_schema
			);
			if ( ! $result['valid'] ) {
				throw self::stable_validation_exception( $result );
			}
			return json_encode(
				$source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR, self::JSON_DEPTH
			);
		}
		$allow_experimental = 0 === $project->schema_version();
		$diagnostics = $this->validator->validate( $payload, $allow_experimental );
		if ( $diagnostics->has_errors() ) {
			throw new ValidationException( $diagnostics );
		}
		$payload['root'] = $this->normalize_node_for_json( $payload['root'] );
		return json_encode(
			$payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR, self::JSON_DEPTH
		);
	}

	/**
	 * Only node/property container boundaries are projected into PHP arrays
	 * for the historical DesignProject model. All nested domain JSON values,
	 * including empty stdClass {}, remain shape-preserved and typed.
	 *
	 * @return array<string,mixed>
	 */
	private function node_to_array( \stdClass $node ): array {
		$data = get_object_vars( $node );
		$data['properties'] = get_object_vars( $node->properties );
		$data['children'] = array_map( fn ( \stdClass $child ): array => $this->node_to_array( $child ), $node->children );
		return $data;
	}

	/** @param array<string,mixed> $node */
	private function normalize_node_for_json( array $node ): object {
		$normalized = $node;
		$normalized['properties'] = (object) ( $node['properties'] ?? [] );
		if ( isset( $normalized['children'] ) && is_array( $normalized['children'] ) ) {
			$normalized['children'] = array_map(
				fn ( mixed $child ): mixed => is_array( $child ) ? $this->normalize_node_for_json( $child ) : $child,
				$normalized['children']
			);
		}
		return (object) $normalized;
	}

	/**
	 * Legacy consumers continue receiving ValidationException; the exact
	 * machine pairs are available without lossy translation directly from
	 * SchemaValidator::validate_stable_v1(), including root pointer ''.
	 *
	 * @param array{valid:bool,diagnostics:list<array{code:string,pointer:string}>} $result
	 */
	private static function stable_validation_exception( array $result ): ValidationException {
		$diagnostics = new Diagnostics();
		foreach ( $result['diagnostics'] as $item ) {
			$diagnostics->error(
				$item['code'],
				'Stable DesignProject validation refused.',
				'' === $item['pointer'] ? 'design' : $item['pointer']
			);
		}
		return new ValidationException( $diagnostics );
	}
}
