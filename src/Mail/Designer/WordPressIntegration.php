<?php
declare(strict_types=1);
/**
 * Presentation adapter for WordPress-owned system notifications.
 *
 * WordPress remains authoritative for event timing, recipients, reset keys,
 * locales and delivery. Mail Designer replaces only subject/body/Content-Type
 * when a registered template renders successfully. Any failure returns the
 * canonical WordPress email unchanged.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Mail\DesignerState;

defined( 'ABSPATH' ) || exit;

final class WordPressIntegration {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted || ! DesignerState::is_enabled() ) {
			return;
		}
		self::$booted = true;

		add_filter( 'retrieve_password_notification_email', [ __CLASS__, 'password_reset' ], 20, 4 );
		add_filter( 'wp_new_user_notification_email', [ __CLASS__, 'new_user' ], 20, 3 );
		add_filter( 'wp_new_user_notification_email_admin', [ __CLASS__, 'new_user_admin' ], 20, 3 );
		add_filter( 'password_change_email', [ __CLASS__, 'password_changed' ], 20, 3 );
		add_filter( 'email_change_email', [ __CLASS__, 'email_changed' ], 20, 3 );
		add_filter( 'wp_password_change_notification_email', [ __CLASS__, 'password_changed_admin' ], 20, 3 );
	}

	/** @param array<string,mixed> $email */
	public static function password_reset( array $email, string $key, string $user_login, \WP_User $user ): array {
		$action_url = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user_login ),
			'login'
		);

		return self::apply_template(
			$email,
			'wordpress.password-reset',
			self::user_context( $user, $action_url, __( 'Reset password', 'core-blueprint' ) )
		);
	}

	/** @param array<string,mixed> $email */
	public static function new_user( array $email, \WP_User $user, string $blogname ): array {
		unset( $blogname );

		// WordPress deliberately does not expose the generated reset key to this
		// filter. Extract only the canonical URL that WordPress already placed in
		// the message; never mint/rotate a new key merely for presentation.
		$action_url = self::first_http_url( (string) ( $email['message'] ?? '' ) );
		if ( '' === $action_url ) {
			return $email;
		}

		return self::apply_template(
			$email,
			'wordpress.new-user',
			self::user_context( $user, $action_url, __( 'Set your password', 'core-blueprint' ) )
		);
	}

	/** @param array<string,mixed> $email */
	public static function new_user_admin( array $email, \WP_User $user, string $blogname ): array {
		unset( $blogname );
		return self::apply_template( $email, 'wordpress.new-user-admin', self::user_context( $user ) );
	}

	/** @param array<string,mixed> $email @param array<string,mixed> $user @param array<string,mixed> $userdata */
	public static function password_changed( array $email, array $user, array $userdata ): array {
		unset( $userdata );
		return self::apply_template( $email, 'wordpress.password-changed', self::array_user_context( $user ) );
	}

	/** @param array<string,mixed> $email @param array<string,mixed> $user @param array<string,mixed> $userdata */
	public static function email_changed( array $email, array $user, array $userdata ): array {
		$context = self::array_user_context( $user );
		$context['user']['new_email'] = sanitize_email( (string) ( $userdata['user_email'] ?? '' ) );
		return self::apply_template( $email, 'wordpress.email-changed', $context );
	}

	/** @param array<string,mixed> $email */
	public static function password_changed_admin( array $email, \WP_User $user, string $blogname ): array {
		unset( $blogname );
		return self::apply_template( $email, 'wordpress.password-changed-admin', self::user_context( $user ) );
	}

	/** @param array<string,mixed> $email @param array<string,mixed> $context @return array<string,mixed> */
	private static function apply_template( array $email, string $template_id, array $context ): array {
		$rendered = Renderer::render( $template_id, $context );
		if ( null === $rendered || '' === trim( $rendered['html'] ) || '' === trim( $rendered['subject'] ) ) {
			return $email;
		}

		$email['subject'] = $rendered['subject'];
		$email['message'] = $rendered['html'];
		$email['headers'] = Renderer::html_headers( $email['headers'] ?? '' );
		return $email;
	}

	/** @return array<string,mixed> */
	private static function user_context( \WP_User $user, string $action_url = '', string $action_label = '' ): array {
		return self::context(
			[
				'display_name' => $user->display_name,
				'login'        => $user->user_login,
				'email'        => $user->user_email,
			],
			$action_url,
			$action_label
		);
	}

	/** @param array<string,mixed> $user @return array<string,mixed> */
	private static function array_user_context( array $user ): array {
		return self::context( [
			'display_name' => (string) ( $user['display_name'] ?? $user['user_login'] ?? '' ),
			'login'        => (string) ( $user['user_login'] ?? '' ),
			'email'        => (string) ( $user['user_email'] ?? '' ),
		] );
	}

	/** @param array<string,mixed> $user @return array<string,mixed> */
	private static function context( array $user, string $action_url = '', string $action_label = '' ): array {
		return [
			'site' => [
				'name'        => (string) get_bloginfo( 'name' ),
				'url'         => home_url( '/' ),
				'admin_email' => sanitize_email( (string) get_option( 'admin_email', '' ) ),
			],
			'user' => [
				'display_name' => sanitize_text_field( (string) ( $user['display_name'] ?? '' ) ),
				'login'        => sanitize_user( (string) ( $user['login'] ?? '' ), true ),
				'email'        => sanitize_email( (string) ( $user['email'] ?? '' ) ),
			],
			'action' => [
				'url'   => esc_url_raw( $action_url, [ 'http', 'https' ] ),
				'label' => sanitize_text_field( $action_label ),
			],
		];
	}

	private static function first_http_url( string $message ): string {
		if ( 1 !== preg_match( '~https?://[^\s<>]+~i', $message, $matches ) ) {
			return '';
		}
		$url = rtrim( (string) ( $matches[0] ?? '' ), "\r\n\t .,)" );
		return esc_url_raw( $url, [ 'http', 'https' ] );
	}

	/** @internal tests */
	public static function _reset_for_testing(): void {
		self::$booted = false;
	}

	private function __construct() {}
}
