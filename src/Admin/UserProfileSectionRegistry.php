<?php
declare(strict_types=1);
/**
 * UserProfileSectionRegistry - public WordPress Profile/Edit User contribution boundary.
 *
 * Base owns collection, ordering, context dispatch and the narrow WP-native
 * Form Composition adapter. Consumers own product semantics, authorization,
 * rendering and persistence. Core Admin presentation is never applied here.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Admin;

use CoreBlueprint\Core\UI\FormComposition;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class UserProfileSectionRegistry {

	public const CONTEXT_SELF = 'self';
	public const CONTEXT_EDIT = 'edit';

	private const CONTEXTS = [
		self::CONTEXT_SELF,
		self::CONTEXT_EDIT,
	];

	/** @var array<string,array{id:string,title:string,order:int,contexts:string[],visible:callable|null,renderer:callable}> */
	private static array $sections = [];

	private static bool $collected = false;
	private static bool $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ], 20 );
		add_action( 'show_user_profile', [ self::class, 'render_self' ], 20 );
		add_action( 'edit_user_profile', [ self::class, 'render_edit' ], 20 );
		ProfileActionForms::init();
	}

	/**
	 * Register one profile section during cb_core_register_user_profile_sections.
	 *
	 * Supported keys:
	 * - title: caller-localized heading
	 * - order: integer sort weight, lower renders first
	 * - contexts: non-empty subset of self|edit
	 * - visible: optional read-only callable(WP_User,string): bool
	 * - renderer: callable(WP_User,string): void
	 *
	 * @param array<string,mixed> $definition
	 */
	public static function register( string $id, array $definition ): bool {
		if ( ! doing_action( 'cb_core_register_user_profile_sections' ) ) {
			self::diagnostic( 'User profile section registration refused outside cb_core_register_user_profile_sections.' );
			return false;
		}

		$id = trim( $id );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/', $id ) ) {
			self::diagnostic( 'Malformed user profile section id refused.' );
			return false;
		}
		if ( isset( self::$sections[ $id ] ) ) {
			self::diagnostic( sprintf( 'Duplicate user profile section refused: %s.', $id ) );
			return false;
		}

		$allowed_keys = [ 'title', 'order', 'contexts', 'visible', 'renderer' ];
		if ( [] !== array_diff( array_keys( $definition ), $allowed_keys ) ) {
			self::diagnostic( sprintf( 'User profile section %s contains unsupported metadata.', $id ) );
			return false;
		}

		$title = isset( $definition['title'] ) && is_string( $definition['title'] )
			? trim( $definition['title'] )
			: '';
		if ( '' === $title ) {
			self::diagnostic( sprintf( 'User profile section %s requires a title.', $id ) );
			return false;
		}

		$order = $definition['order'] ?? 100;
		if ( ! is_int( $order ) || $order < -1000 || $order > 1000 ) {
			self::diagnostic( sprintf( 'User profile section %s has an invalid order.', $id ) );
			return false;
		}

		$raw_contexts = is_array( $definition['contexts'] ?? null ) ? $definition['contexts'] : [];
		if ( [] === $raw_contexts ) {
			self::diagnostic( sprintf( 'User profile section %s requires at least one context.', $id ) );
			return false;
		}
		$contexts = [];
		foreach ( $raw_contexts as $context ) {
			if ( ! is_string( $context ) || ! in_array( $context, self::CONTEXTS, true ) ) {
				self::diagnostic( sprintf( 'User profile section %s requested an unsupported context.', $id ) );
				return false;
			}
			$contexts[ $context ] = $context;
		}
		$contexts = array_values( $contexts );

		$visible = $definition['visible'] ?? null;
		if ( null !== $visible && ! is_callable( $visible ) ) {
			self::diagnostic( sprintf( 'User profile section %s visibility callback is not callable.', $id ) );
			return false;
		}

		$renderer = $definition['renderer'] ?? null;
		if ( ! is_callable( $renderer ) ) {
			self::diagnostic( sprintf( 'User profile section %s renderer is not callable.', $id ) );
			return false;
		}

		self::$sections[ $id ] = [
			'id'       => $id,
			'title'    => $title,
			'order'    => $order,
			'contexts' => $contexts,
			'visible'  => $visible,
			'renderer' => $renderer,
		];
		return true;
	}

	public static function collect(): void {
		if ( self::$collected ) {
			return;
		}
		self::$collected = true;
		do_action( 'cb_core_register_user_profile_sections' );
	}

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		self::collect();
		$sections = self::$sections;
		uasort(
			$sections,
			static function ( array $a, array $b ): int {
				$order = (int) $a['order'] <=> (int) $b['order'];
				return 0 !== $order ? $order : strcmp( (string) $a['id'], (string) $b['id'] );
			}
		);
		return $sections;
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		$context = self::context_for_hook( $hook_suffix );
		if ( null === $context || ! self::has_context( $context ) ) {
			return;
		}
		FormComposition::enqueue( FormComposition::PRESENTATION_WP_NATIVE );
	}

	public static function render_self( WP_User $profile_user ): void {
		self::render_context( $profile_user, self::CONTEXT_SELF );
	}

	public static function render_edit( WP_User $profile_user ): void {
		self::render_context( $profile_user, self::CONTEXT_EDIT );
	}

	/** Reset request-local registry state for tests. */
	public static function _reset_for_testing(): void {
		self::$sections = [];
		self::$collected = false;
	}

	private static function render_context( WP_User $profile_user, string $context ): void {
		foreach ( self::all() as $section ) {
			if ( ! in_array( $context, $section['contexts'], true ) ) {
				continue;
			}
			if ( is_callable( $section['visible'] ) && ! (bool) call_user_func( $section['visible'], $profile_user, $context ) ) {
				continue;
			}

			echo '<h2 id="cb-core-user-profile-' . esc_attr( (string) $section['id'] ) . '">' . esc_html( (string) $section['title'] ) . '</h2>';
			call_user_func( $section['renderer'], $profile_user, $context );
		}
	}

	private static function has_context( string $context ): bool {
		foreach ( self::all() as $section ) {
			if ( in_array( $context, $section['contexts'], true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function context_for_hook( string $hook_suffix ): ?string {
		if ( 'profile.php' === $hook_suffix ) {
			return self::CONTEXT_SELF;
		}
		if ( 'user-edit.php' === $hook_suffix ) {
			return self::CONTEXT_EDIT;
		}
		return null;
	}

	private static function diagnostic( string $message ): void {
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( __METHOD__, esc_html( $message ), '1.0.0' );
			return;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Core Blueprint UserProfileSectionRegistry] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-only fallback when _doing_it_wrong() is unavailable.
		}
	}
}
