<?php
declare(strict_types=1);
/**
 * RunController - REST endpoints for the Console runner.
 *
 * Two routes:
 *
 *   GET  /core-blueprint/v1/console/commands
 *        Returns the full Registry::commands() list with each command's
 *        args_schema() and side_effects() so the UI can render the
 *        picker + form without a per-command roundtrip.
 *
 *   POST /core-blueprint/v1/console/run
 *        Body: { id: <command-id>, args: { … } }
 *        Looks up the command by id, checks capability, normalises args
 *        through the command's schema, calls execute(), serialises the
 *        Result back as JSON. Logs a `console.executed` audit-event per
 *        invocation regardless of outcome.
 *
 * Capability: every endpoint requires `cb_use_cli`. Per-command
 * capabilities are checked individually too - a command can declare
 * its own capability override and the runner respects it.
 *
 * Runnable gate: Registry exposes only commands that implement
 * CommandInterface. 'none'/'state' commands run directly; 'destructive'
 * commands require a confirm token, issued by GET /console/confirm-token
 * after the UI confirm modal. A command that cannot be instantiated as
 * CommandInterface fails closed with 423 Locked as an invalid registration.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CB\Core\Console\Rest;

use CB\Core\Console\CommandInterface;
use CB\Core\Console\Registry;
use CB\Core\Console\Result;
use CB\Core\Log\AuditLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class RunController {

	private const REST_NAMESPACE = 'core-blueprint/v1';

	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/console/commands',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'handle_commands' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
			]
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/console/run',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle_run' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
				'args'                => [
					'id'   => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
					'args' => [
						'type'     => 'object',
						'required' => false,
						'default'  => [],
					],
					'confirm_token' => [
						'type'     => 'string',
						'required' => false,
						'default'  => '',
					],
				],
			]
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/console/confirm-token',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle_confirm_token' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
				'args'                => [
					'id' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/console/user-search',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'handle_user_search' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
				'args'                => [
					'q' => [
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'limit' => [
						'type'    => 'integer',
						'default' => 8,
					],
				],
			]
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/console/job-progress',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'handle_job_progress' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
				'args'                => [
					'job_id' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Permission gate - every Console REST call requires `cb_use_cli`.
	 * Per-command capabilities are checked again inside handle_run when
	 * the command's metadata declares an override.
	 */
	public static function check_permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'cb_use_cli' ) ) {
			return new WP_Error(
				'cb_console_forbidden',
				__( 'You do not have permission to use the Console.', 'core-blueprint' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Return the command catalog with schema + side-effects metadata.
	 * Each entry is annotated with `runnable` based on whether the
	 * Console can execute the command. Registry validation guarantees every
	 * listed command implements CommandInterface; runtime capability checks
	 * determine whether the current operator may execute it.
	 */
	public static function handle_commands( WP_REST_Request $request ) {
		$commands = Registry::commands();
		$out      = [];

		foreach ( $commands as $cmd ) {
			$entry = [
				'id'           => $cmd['id'],
				'name'         => $cmd['name'],
				'description'  => $cmd['description'],
				'group'        => $cmd['group'],
				'capability'   => $cmd['capability'],
				'side_effects' => 'unknown',
				'args_schema'  => [],
				'runnable'     => false,
				'reason'       => 'unknown',
			];

			$class    = $cmd['class'];
			$instance = self::instantiate_or_null( $class );

			if ( ! $instance instanceof CommandInterface ) {
				continue;
			}
			$entry['side_effects'] = $instance->side_effects();
			$entry['args_schema']  = self::normalise_schema( $instance->args_schema() );
			$capability = (string) ( $cmd['capability'] ?? '' );
			$allowed = '' === $capability || current_user_can( $capability );
			$entry['runnable'] = $allowed;
			$entry['reason']   = $allowed ? 'available' : 'capability-denied';

			$out[] = $entry;
		}

		return new WP_REST_Response(
			[
				'commands' => $out,
				'note'     => __( 'All commands runnable from the Console.', 'core-blueprint' ),
			],
			200
		);
	}

	/**
	 * Run a command. Validates id, looks up the command class, checks
	 * the runnable gate + per-command capability, normalises args,
	 * calls execute(), audit-logs the outcome.
	 *
	 * Destructive commands require the request
	 * body to include `confirm_token: '<command_id>:<csrf-nonce-tail>'`.
	 * That's an additional check on top of the WP REST nonce - it ensures
	 * the destructive run was triggered through the explicit confirm-modal
	 * rather than an accidental form-submit or replayed network request.
	 * The token format is verified server-side; any mismatch returns 400.
	 */
	public static function handle_run( WP_REST_Request $request ) {
		$id   = (string) $request->get_param( 'id' );
		$args = $request->get_param( 'args' );
		if ( ! is_array( $args ) ) {
			$args = [];
		}

		$cmd = Registry::find( $id );
		if ( null === $cmd ) {
			return new WP_Error(
				'cb_console_unknown_command',
				/* translators: %s: command ID. */
				sprintf( __( 'Unknown command: %s', 'core-blueprint' ), $id ),
				[ 'status' => 404 ]
			);
		}

		// Per-command capability - defaults to cb_use_cli (already
		// checked by check_permission). Commands declaring a stricter
		// cap get re-checked here.
		$cap = (string) $cmd['capability'];
		if ( '' !== $cap && 'cb_use_cli' !== $cap && ! current_user_can( $cap ) ) {
			return new WP_Error(
				'cb_console_capability_denied',
				/* translators: %s: required WordPress capability. */
				sprintf( __( 'You do not have the %s capability required to run this command.', 'core-blueprint' ), $cap ),
				[ 'status' => 403 ]
			);
		}

		$instance = self::instantiate_or_null( $cmd['class'] );
		if ( ! $instance instanceof CommandInterface ) {
			return new WP_Error(
				'cb_console_invalid_command',
				__( 'This command registration is invalid.', 'core-blueprint' ),
				[ 'status' => 423 ]
			);
		}

		$side_effects = $instance->side_effects();

		// Destructive gate - require an explicit confirm token. The token
		// is generated by the UI after the operator clicks "Confirm" in
		// the modal and exists for the lifetime of one request only.
		// Format: `console:{command_id}` HMAC'd with wp_create_nonce so
		// the request must carry a second, command-bound intent nonce produced
		// by the explicit confirmation flow. This is a consequence guard, not
		// a second authentication factor.
		if ( 'destructive' === $side_effects ) {
			$token   = (string) $request->get_param( 'confirm_token' );
			$expected = wp_create_nonce( 'cb_console_confirm:' . $id );
			if ( '' === $token || ! hash_equals( $expected, $token ) ) {
				return new WP_Error(
					'cb_console_confirm_required',
					__( 'Destructive commands require an explicit confirm token. Re-open the confirm dialog and try again.', 'core-blueprint' ),
					[ 'status' => 400, 'side_effects' => 'destructive' ]
				);
			}
		}

		$schema     = self::normalise_schema( $instance->args_schema() );
		$normalised = self::normalise_args( $schema, $args );

		$started = microtime( true );
		try {
			$result = $instance->execute( $normalised );
		} catch ( \Throwable $t ) {
			$result = Result::error(
				__( 'Command threw an exception: ', 'core-blueprint' ) . $t->getMessage(),
				[ $t->getMessage() ]
			);
		}
		$duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		// Audit log - every run, regardless of outcome. Side-effects level
		// determines log severity so destructive runs leap out in Logs view.
		$severity = match ( true ) {
			'error'   === $result->status            => 'warning',
			'warning' === $result->status            => 'notice',
			'destructive' === $side_effects          => 'notice',
			default                                  => 'info',
		};

		AuditLog::log( 'console.executed', $severity, [
			'command_id'   => $cmd['id'],
			'command_name' => $cmd['name'],
			'status'       => $result->status,
			'side_effects' => $side_effects,
			'duration_ms'  => $duration_ms,
			'via'          => 'console',
		] );

		return new WP_REST_Response(
			array_merge(
				$result->to_array(),
				[
					'command_id'   => $cmd['id'],
					'duration_ms'  => $duration_ms,
					'side_effects' => $side_effects,
				]
			),
			200
		);
	}

	/**
	 * Issue a confirm-token for a destructive command. Called by the JS
	 * confirm-modal when the operator clicks "Confirm". The returned
	 * token is single-use-ish - it's a wp_create_nonce-based HMAC tied
	 * to the command id and the current user's session, so it can be
	 * replayed within the nonce-validity window (24 hours by default)
	 * but only for that one command-id-and-user combination.
	 *
	 * The endpoint exists as a separate route so the UI can issue the
	 * token at confirm-click time rather than at command-pick time -
	 * minimises the window in which a token sits in browser memory.
	 */
	public static function handle_confirm_token( WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'id' );
		$cmd = Registry::find( $id );
		if ( null === $cmd ) {
			return new WP_Error(
				'cb_console_unknown_command',
				/* translators: %s: command ID. */
				sprintf( __( 'Unknown command: %s', 'core-blueprint' ), $id ),
				[ 'status' => 404 ]
			);
		}

		$cap = (string) ( $cmd['capability'] ?? '' );
		if ( '' !== $cap && ! current_user_can( $cap ) ) {
			return new WP_Error(
				'cb_console_capability_denied',
				/* translators: %s: required WordPress capability. */
				sprintf( __( 'You do not have the %s capability required to confirm this command.', 'core-blueprint' ), $cap ),
				[ 'status' => 403 ]
			);
		}

		$instance = self::instantiate_or_null( $cmd['class'] );
		if ( ! $instance instanceof CommandInterface ) {
			return new WP_Error(
				'cb_console_invalid_command',
				__( 'This command registration is invalid.', 'core-blueprint' ),
				[ 'status' => 423 ]
			);
		}

		if ( 'destructive' !== $instance->side_effects() ) {
			return new WP_Error(
				'cb_console_no_confirm_required',
				__( 'This command does not require a confirm token.', 'core-blueprint' ),
				[ 'status' => 400 ]
			);
		}

		return new WP_REST_Response(
			[
				'token'      => wp_create_nonce( 'cb_console_confirm:' . $id ),
				'command_id' => $id,
			],
			200
		);
	}

	/**
	 * User-search endpoint for the Console's user-picker autocomplete.
	 *
	 * Returns up to N users matching `q` against login, email, or
	 * display name - same precedence as the CLI's resolve_user. The
	 * Console renders the result list as a dropdown under the input,
	 * and the operator selects one (which writes the user_id into the
	 * form value, not the displayed string).
	 *
	 * Permission gate is the same `cb_use_cli` cap everything else uses.
	 * No additional capability check beyond that - operators are trusted
	 * to see usernames since they're already inside the operator surface.
	 *
	 * Empty query returns a recent-operators list as a sensible default
	 * (operators are the most likely user references in the Console
	 * context - no point making the operator type a single character
	 * just to get a starting list).
	 */
	public static function handle_user_search( WP_REST_Request $request ) {
		$query = (string) $request->get_param( 'q' );
		$limit = (int) $request->get_param( 'limit' );
		$limit = max( 1, min( $limit, 20 ) );

		$users = self::search_users( $query, $limit );

		$rows = [];
		foreach ( $users as $user ) {
			$rows[] = [
				'id'           => (int) $user->ID,
				'login'        => (string) $user->user_login,
				'email'        => (string) $user->user_email,
				'display_name' => (string) $user->display_name,
			];
		}

		return new WP_REST_Response(
			[
				'query'   => $query,
				'results' => $rows,
				'count'   => count( $rows ),
			],
			200
		);
	}

	/**
	 * @return array<int, \WP_User>
	 */
	private static function search_users( string $query, int $limit ): array {
		$query = trim( $query );

		// Empty query → recent users (registration order, descending) capped at the limit.
		// Could be replaced by "recent operators" but recent users is a more useful
		// default for "I just created this user, now make them an operator".
		if ( '' === $query ) {
			$wp_query = new \WP_User_Query( [
				'number'  => $limit,
				'orderby' => 'registered',
				'order'   => 'DESC',
			] );
			$users = $wp_query->get_results();
			return is_array( $users ) ? $users : [];
		}

		// Numeric → ID lookup first (precedence parity with resolve_user).
		if ( ctype_digit( $query ) ) {
			$user = get_userdata( (int) $query );
			if ( $user ) {
				return [ $user ];
			}
		}

		// Multi-field search via WP_User_Query - login/nicename/email/display_name
		// matched as a wildcard substring. The `*` wraps build a LIKE '%foo%' query.
		$wp_query = new \WP_User_Query( [
			'search'         => '*' . esc_sql( $query ) . '*',
			'search_columns' => [ 'user_login', 'user_email', 'user_nicename', 'display_name' ],
			'number'         => $limit,
			'orderby'        => 'login',
			'order'          => 'ASC',
		] );

		$users = $wp_query->get_results();
		return is_array( $users ) ? $users : [];
	}

	/**
	 * Job-progress endpoint for async commands (currently `cb scan run`).
	 *
	 * The persisted Scanner job + scan lock are authoritative. The transient is
	 * only an optional presentation cache, so expiry never turns a live scan into
	 * a false "gone" state. Completion is matched by exact job_id against the
	 * latest result. Large finding sets stay in the Scanner repository instead of
	 * being repeated through this one-second polling endpoint.
	 */
	public static function handle_job_progress( WP_REST_Request $request ) {
		$job_id = (string) $request->get_param( 'job_id' );
		if ( '' === $job_id ) {
			return new WP_Error(
				'cb_console_missing_job_id',
				__( 'job_id is required.', 'core-blueprint' ),
				[ 'status' => 400 ]
			);
		}

		$state = \CB\Core\Integrity\Scanner\TransientProgressReporter::read( $job_id );
		if ( is_array( $state ) && in_array( (string) ( $state['status'] ?? '' ), [ 'pending', 'running' ], true ) ) {
			$job = \CB\Core\Integrity\Scanner\ScanJobRepository::get_by_id( $job_id );
			if ( ! is_array( $job ) || ! \CB\Core\Integrity\Scanner\ScanJobStatus::is_active_job( $job ) ) {
				$state = null;
				\CB\Core\Integrity\Scanner\TransientProgressReporter::clear( $job_id );
			}
		}

		if ( null === $state ) {
			$job = \CB\Core\Integrity\Scanner\ScanJobRepository::get_by_id( $job_id );
			if ( is_array( $job ) && \CB\Core\Integrity\Scanner\ScanJobStatus::is_active_job( $job ) ) {
				$state = \CB\Core\Integrity\Scanner\ScanJobStatus::progress_from_job( $job );
			} else {
				$latest = class_exists( \CB\Core\Integrity\Storage\ResultRepository::class )
					? ( \CB\Core\Integrity\Storage\ResultRepository::getLatest() ?? [] )
					: [];
				if ( is_array( $latest ) && $job_id === (string) ( $latest['job_id'] ?? '' ) ) {
					$state = [
						'job_id'       => $job_id,
						'status'       => 'done',
						'current_phase'=> '',
						'completed_at' => null,
						'error'        => null,
					];
				} else {
					return new WP_REST_Response(
						[
							'status'       => 'gone',
							'phase'        => '',
							'started_at'   => null,
							'completed_at' => null,
							'error'        => null,
							'final_result' => null,
						],
						200
					);
				}
			}
		}

		$status = (string) ( $state['status'] ?? 'pending' );
		$response = [
			'status'       => $status,
			'phase'        => (string) ( $state['current_phase'] ?? '' ),
			'started_at'   => isset( $state['started_at'] )   ? (float) $state['started_at']   : null,
			'completed_at' => isset( $state['completed_at'] ) ? (float) $state['completed_at'] : null,
			'error'        => isset( $state['error'] )        ? (string) $state['error']       : null,
			'final_result' => null,
		];

		if ( 'done' === $status && class_exists( \CB\Core\Integrity\Storage\ResultRepository::class ) ) {
			$latest  = \CB\Core\Integrity\Storage\ResultRepository::getLatest() ?? [];
			$summary = \CB\Core\Integrity\Storage\ResultRepository::getSummary();
			$issues  = (int) ( $summary['totals']['issues'] ?? $summary['issues'] ?? 0 );

			$lines   = [];
			$lines[] = '';
			$lines[] = 'Scan complete.';
			$lines[] = str_repeat( '─', 40 );
			$lines[] = 'Status:        ' . (string) ( $latest['status']       ?? 'unknown' );
			$lines[] = 'Completed at:  ' . (string) ( $latest['completed_at'] ?? '-' );
			$lines[] = 'Source:        ' . (string) ( $latest['source']       ?? '-' );
			$lines[] = 'Issues:        ' . $issues;
			$lines[] = '';

			$message = sprintf(
				/* translators: %d: issue count */
				_n( 'Scan complete with %d issue.', 'Scan complete with %d issues.', $issues, 'core-blueprint' ),
				$issues
			);

			$response['final_result'] = [
				'status'  => 'success',
				'message' => $message,
				'lines'   => $lines,
				'data'    => [
					'job_id'       => (string) ( $latest['job_id'] ?? '' ),
					'status'       => (string) ( $latest['status'] ?? 'unknown' ),
					'completed_at' => (string) ( $latest['completed_at'] ?? '' ),
					'source'       => (string) ( $latest['source'] ?? '' ),
					'completion'   => (string) ( $latest['completion'] ?? 'incomplete' ),
					'summary'      => is_array( $latest['summary'] ?? null ) ? $latest['summary'] : [],
				],
			];
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Defensive class instantiation - returns null when the class
	 * doesn't exist or its constructor blows up. Keeps the REST surface
	 * stable when a sibling plugin registers a malformed entry.
	 */
	private static function instantiate_or_null( string $class ): ?object {
		if ( ! class_exists( $class ) ) {
			return null;
		}
		try {
			return new $class();
		} catch ( \Throwable $t ) {
			return null;
		}
	}

	/**
	 * Normalise a schema array - fill defaults so the UI doesn't have
	 * to repeat them. Also coerces unknown types to 'text'.
	 *
	 * @param array<string, array<string, mixed>> $schema
	 * @return array<string, array<string, mixed>>
	 */
	private static function normalise_schema( array $schema ): array {
		$out = [];
		foreach ( $schema as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$type = isset( $entry['type'] ) ? (string) $entry['type'] : 'text';
			if ( ! in_array( $type, [ 'boolean', 'text', 'int', 'select', 'user', 'date' ], true ) ) {
				$type = 'text';
			}
			$out[ $key ] = [
				'key'      => (string) $key,
				'type'     => $type,
				'label'    => (string) ( $entry['label']    ?? $key ),
				'required' => (bool)   ( $entry['required'] ?? false ),
				'default'  => $entry['default'] ?? null,
				'options'  => isset( $entry['options'] ) && is_array( $entry['options'] ) ? $entry['options'] : [],
				'help'     => (string) ( $entry['help']     ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * Coerce raw args from the request into the types declared by the
	 * schema. Out-of-schema keys are dropped - the runner only forwards
	 * what the command actually expects.
	 *
	 * @param array<string, array<string, mixed>> $schema
	 * @param array<string, mixed>                $args
	 * @return array<string, mixed>
	 */
	private static function normalise_args( array $schema, array $args ): array {
		$out = [];
		foreach ( $schema as $key => $entry ) {
			$present = array_key_exists( $key, $args );
			$value   = $present ? $args[ $key ] : ( $entry['default'] ?? null );

			switch ( $entry['type'] ) {
				case 'boolean':
					$out[ $key ] = (bool) $value;
					break;
				case 'int':
					if ( null === $value || '' === $value ) {
						$out[ $key ] = null;
					} else {
						$out[ $key ] = (int) $value;
					}
					break;
				case 'select':
					$value = (string) $value;
					$opts  = is_array( $entry['options'] ) ? $entry['options'] : [];
					if ( ! empty( $opts ) && ! array_key_exists( $value, $opts ) ) {
						$value = (string) ( $entry['default'] ?? '' );
					}
					$out[ $key ] = $value;
					break;
				case 'date':
					// Accept YYYY-MM-DD; reject anything else by emptying the value.
					// Empty triggers the command's default-date logic.
					$value = (string) ( $value ?? '' );
					$out[ $key ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
					break;
				case 'user':
				case 'text':
				default:
					$out[ $key ] = (string) ( $value ?? '' );
					break;
			}
		}
		return $out;
	}
}
