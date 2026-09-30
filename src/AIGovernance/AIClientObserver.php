<?php
declare(strict_types=1);
/**
 * WordPress AI Client lifecycle observer.
 *
 * The observer records metadata only. Messages, generated content, provider
 * response bodies and credentials are never persisted.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CB\Core\AIGovernance;

defined( 'ABSPATH' ) || exit;

final class AIClientObserver {
	/** @var array<int,array<int,array{id:string,start_ns:int}>> */
	private static array $stacks = [];

	public static function boot(): void {
		add_action( 'wp_ai_client_before_generate_result', [ __CLASS__, 'on_before_generate_result' ], 10, 1 );
		add_action( 'wp_ai_client_after_generate_result', [ __CLASS__, 'on_after_generate_result' ], 10, 1 );
		add_action( 'wp_ai_client_before_generate_embedding', [ __CLASS__, 'on_before_generate_result' ], 10, 1 );
		add_action( 'wp_ai_client_after_generate_embedding', [ __CLASS__, 'on_after_generate_result' ], 10, 1 );
		add_action( 'shutdown', [ __CLASS__, 'flush_open' ], PHP_INT_MAX - 1 );
	}

	public static function on_before_generate_result( object $event ): void {
		self::observe( static function () use ( $event ): void {
			$descriptor = self::describe_event( $event );
			if ( null === $descriptor ) {
				return;
			}

			$link = TraceContext::link();
			$activity_id = wp_generate_uuid4();
			$source = SourceContext::detect();
			$id = Repository::insert( [
				'activity_id'       => $activity_id,
				'correlation_id'    => $link['correlation_id'],
				'parent_activity_id' => $link['parent_activity_id'],
				'operation_type'    => 'ai-client',
				'operation'         => 'wordpress-ai-client/' . $descriptor['capability'],
				'transport'         => $source['transport'],
				'source_id'         => 'wordpress-ai-client',
				'source_label'      => 'WordPress AI Client',
				'provider_id'       => $descriptor['provider_id'],
				'provider_label'    => $descriptor['provider_label'],
				'model_id'          => $descriptor['model_id'],
				'model_label'       => $descriptor['model_label'],
				'outcome'           => 'unknown',
				'capture_state'     => 'generation-started',
				'evidence'          => array_replace_recursive(
					self::request_evidence( $descriptor ),
					$source['evidence']
				),
			] );
			if ( false === $id ) {
				return;
			}

			$key = $descriptor['model_key'];
			self::$stacks[ $key ] ??= [];
			self::$stacks[ $key ][] = [
				'id'       => $id,
				'start_ns' => hrtime( true ),
			];
		} );
	}

	public static function on_after_generate_result( object $event ): void {
		self::observe( static function () use ( $event ): void {
			$descriptor = self::describe_event( $event );
			if ( null === $descriptor ) {
				return;
			}
			$key = $descriptor['model_key'];
			$frame = self::pop_frame( $key );
			if ( null === $frame ) {
				return;
			}

			$evidence = [
				'capability' => $descriptor['capability'],
			];
			if ( method_exists( $event, 'getResult' ) ) {
				$result = $event->getResult();
				if ( is_object( $result ) ) {
					$evidence['result'] = self::summarize_result( $result );
				}
			}

			$duration_ms = max( 0, (int) round( ( hrtime( true ) - $frame['start_ns'] ) / 1_000_000 ) );
			Repository::update( $frame['id'], [
				'outcome'       => 'succeeded',
				'capture_state' => 'completed',
				'duration_ms'   => $duration_ms,
				'evidence'      => $evidence,
				'completed_at'  => gmdate( 'Y-m-d H:i:s' ),
			] );
		} );
	}

	public static function flush_open(): void {
		self::observe( static function (): void {
			foreach ( self::$stacks as $frames ) {
				foreach ( $frames as $frame ) {
					Repository::update( $frame['id'], [
						'capture_state' => 'generation-started',
					] );
				}
			}
			self::$stacks = [];
		} );
	}

	/** @return array{model_key:int,capability:string,message_count:?int,input_count:?int,provider_id:?string,provider_label:?string,model_id:?string,model_label:?string}|null */
	private static function describe_event( object $event ): ?array {
		if ( ! method_exists( $event, 'getModel' ) ) {
			return null;
		}
		$model = $event->getModel();
		if ( ! is_object( $model ) ) {
			return null;
		}

		$capability = 'unknown';
		if ( method_exists( $event, 'getCapability' ) ) {
			$value = $event->getCapability();
			if ( is_object( $value ) && isset( $value->value ) && is_string( $value->value ) ) {
				$capability = sanitize_key( $value->value );
			} elseif ( is_string( $value ) ) {
				$capability = sanitize_key( $value );
			} elseif ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
				$capability = sanitize_key( (string) $value );
			}
		}
		if ( '' === $capability ) {
			$capability = 'unknown';
		}

		$message_count = null;
		if ( method_exists( $event, 'getMessages' ) ) {
			$messages = $event->getMessages();
			$message_count = is_array( $messages ) ? count( $messages ) : 0;
		}

		$input_count = null;
		if ( method_exists( $event, 'getInputs' ) ) {
			$inputs = $event->getInputs();
			$input_count = is_array( $inputs ) ? count( $inputs ) : 0;
		}

		$provider_id = null;
		$provider_label = null;
		if ( method_exists( $model, 'providerMetadata' ) ) {
			$metadata = $model->providerMetadata();
			if ( is_object( $metadata ) ) {
				$provider_id = self::metadata_string( $metadata, 'getId' );
				$provider_label = self::metadata_string( $metadata, 'getName' );
			}
		}

		$model_id = null;
		$model_label = null;
		if ( method_exists( $model, 'metadata' ) ) {
			$metadata = $model->metadata();
			if ( is_object( $metadata ) ) {
				$model_id = self::metadata_string( $metadata, 'getId' );
				$model_label = self::metadata_string( $metadata, 'getName' );
			}
		}

		return [
			'model_key'      => spl_object_id( $model ),
			'capability'     => substr( $capability, 0, 100 ),
			'message_count'  => null === $message_count ? null : max( 0, $message_count ),
			'input_count'    => null === $input_count ? null : max( 0, $input_count ),
			'provider_id'    => $provider_id,
			'provider_label' => $provider_label,
			'model_id'       => $model_id,
			'model_label'    => $model_label,
		];
	}

	/** @param array{capability:string,message_count:?int,input_count:?int} $descriptor @return array<string,mixed> */
	private static function request_evidence( array $descriptor ): array {
		$evidence = [
			'capture' => [
				'platform' => 'wordpress-ai-client',
			],
			'capability' => $descriptor['capability'],
		];
		if ( null !== $descriptor['message_count'] ) {
			$evidence['message_count'] = $descriptor['message_count'];
		}
		if ( null !== $descriptor['input_count'] ) {
			$evidence['input_count'] = $descriptor['input_count'];
		}
		return $evidence;
	}

	/** @return array<string,mixed> */
	private static function summarize_result( object $result ): array {
		$summary = [
			'type' => sanitize_text_field( get_class( $result ) ),
		];

		if ( method_exists( $result, 'getCandidateCount' ) ) {
			$summary['candidate_count'] = max( 0, (int) $result->getCandidateCount() );
		}
		if ( method_exists( $result, 'getEmbeddings' ) ) {
			$embeddings = $result->getEmbeddings();
			$summary['embedding_count'] = is_array( $embeddings ) ? count( $embeddings ) : 0;
		}
		if ( method_exists( $result, 'getDimensions' ) ) {
			$summary['dimensions'] = max( 0, (int) $result->getDimensions() );
		}

		if ( method_exists( $result, 'getTokenUsage' ) ) {
			$usage = $result->getTokenUsage();
			if ( is_object( $usage ) ) {
				$tokens = [];
				foreach ( [
					'prompt'     => 'getPromptTokens',
					'completion' => 'getCompletionTokens',
					'total'      => 'getTotalTokens',
					'thought'    => 'getThoughtTokens',
				] as $key => $method ) {
					if ( ! method_exists( $usage, $method ) ) {
						continue;
					}
					$value = $usage->{$method}();
					if ( null !== $value ) {
						$tokens[ $key ] = max( 0, (int) $value );
					}
				}
				if ( [] !== $tokens ) {
					$summary['token_usage'] = $tokens;
				}
			}
		}

		return $summary;
	}

	private static function metadata_string( object $metadata, string $method ): ?string {
		if ( ! method_exists( $metadata, $method ) ) {
			return null;
		}
		$value = $metadata->{$method}();
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = trim( sanitize_text_field( (string) $value ) );
		return '' === $value ? null : substr( $value, 0, 190 );
	}

	/** @return array{id:string,start_ns:int}|null */
	private static function pop_frame( int $key ): ?array {
		if ( empty( self::$stacks[ $key ] ) ) {
			return null;
		}
		$frame = array_pop( self::$stacks[ $key ] );
		if ( [] === self::$stacks[ $key ] ) {
			unset( self::$stacks[ $key ] );
		}
		return is_array( $frame ) ? $frame : null;
	}

	private static function observe( callable $callback ): void {
		try {
			$callback();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/** @internal */
	public static function reset_for_tests(): void {
		self::$stacks = [];
	}
}
