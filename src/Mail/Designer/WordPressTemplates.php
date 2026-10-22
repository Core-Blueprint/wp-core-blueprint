<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Design\Profile\Mail\Contract;

defined( 'ABSPATH' ) || exit;

/** Native v1 defaults, never derived by conversion of experimental documents. */
final class WordPressTemplates {
	public static function register(): void {
		foreach ( self::templates() as $id => $template ) {
			TemplateRegistry::register( [
				'id' => $id, 'provider' => 'core', 'group' => 'wordpress',
				'label' => $template['label'], 'description' => $template['description'],
				'subject' => $template['subject'], 'project' => self::build( $template )['project'],
			] );
		}
	}

	/** A distinct, complete {project,fields} default for every WP resource ID. */
	public static function default_document( string $resource_id ): ?array {
		$templates = self::templates();
		if ( ! isset( $templates[ $resource_id ] ) ) return null;
		return self::build( $templates[ $resource_id ] );
	}

	private static function build( array $template ): array {
		$content = [
			Contract::node( 'mail.heading', [], [ 'text' => $template['heading'] ] ),
			Contract::node( 'mail.text', [], [ 'text' => $template['body'], 'spacing' => 20 ] ),
		];
		if ( isset( $template['action_label'] ) ) {
			$content[] = Contract::node( 'mail.button', [], [
				'label' => $template['action_label'], 'url' => '{{action.url}}',
			] );
		}
		$content[] = Contract::node( 'mail.divider' );
		$content[] = Contract::node( 'mail.text', [], [
			'text' => __( 'Sent by {{site.name}} — {{site.url}}', 'core-blueprint' ),
			'fontSize' => 12, 'color' => '#6b7280', 'spacing' => 0,
		] );
		return [
			'project' => [
				'schema_version' => 1,
				'design_type' => Contract::DESIGN_TYPE,
				'root' => Contract::node( Contract::ROOT_TYPE, [
					Contract::node( 'mail.section', $content ),
				] ),
			],
			'fields' => [
				'subject' => $template['subject'],
				'preheader' => __( 'A message from {{site.name}}', 'core-blueprint' ),
			],
		];
	}

	private static function templates(): array {
		return [
			'wordpress.password-reset' => [
				'label' => __( 'Password reset', 'core-blueprint' ),
				'description' => __( 'Password reset email sent to a user.', 'core-blueprint' ),
				'subject' => __( 'Reset your password for {{site.name}}', 'core-blueprint' ),
				'heading' => __( 'Reset your password', 'core-blueprint' ),
				'body' => __( "Hi {{user.display_name}},\n\nWe received a request to reset the password for your account. If this was you, use the button below. If not, you can ignore this email.", 'core-blueprint' ),
				'action_label' => __( 'Reset password', 'core-blueprint' ),
			],
			'wordpress.new-user' => [
				'label' => __( 'New user — user', 'core-blueprint' ),
				'description' => __( 'Welcome email sent to a newly created WordPress user.', 'core-blueprint' ),
				'subject' => __( 'Your account at {{site.name}}', 'core-blueprint' ),
				'heading' => __( 'Welcome to {{site.name}}', 'core-blueprint' ),
				'body' => __( "Hi {{user.display_name}},\n\nAn account has been created for you. Your username is {{user.login}}. Use the button below to set your password and sign in.", 'core-blueprint' ),
				'action_label' => __( 'Set your password', 'core-blueprint' ),
			],
			'wordpress.new-user-admin' => [
				'label' => __( 'New user — admin', 'core-blueprint' ),
				'description' => __( 'Notification sent to the site administrator when a user is created.', 'core-blueprint' ),
				'subject' => __( 'New user registration on {{site.name}}', 'core-blueprint' ),
				'heading' => __( 'New user registration', 'core-blueprint' ),
				'body' => __( "A new user has been registered on {{site.name}}.\n\nUsername: {{user.login}}\nEmail: {{user.email}}", 'core-blueprint' ),
			],
			'wordpress.password-changed' => [
				'label' => __( 'Password changed — user', 'core-blueprint' ),
				'description' => __( 'Security notification sent when a user password changes.', 'core-blueprint' ),
				'subject' => __( 'Your password was changed on {{site.name}}', 'core-blueprint' ),
				'heading' => __( 'Your password was changed', 'core-blueprint' ),
				'body' => __( "Hi {{user.display_name}},\n\nThe password for your account {{user.login}} was changed. If you did not make this change, contact the site administrator at {{site.admin_email}}.", 'core-blueprint' ),
			],
			'wordpress.email-changed' => [
				'label' => __( 'Email changed — user', 'core-blueprint' ),
				'description' => __( 'Security notification sent to the previous address after an email change.', 'core-blueprint' ),
				'subject' => __( 'Your email address was changed on {{site.name}}', 'core-blueprint' ),
				'heading' => __( 'Your email address was changed', 'core-blueprint' ),
				'body' => __( "Hi {{user.display_name}},\n\nThe email address for your account {{user.login}} was changed. If you did not make this change, contact the site administrator at {{site.admin_email}}.", 'core-blueprint' ),
			],
			'wordpress.password-changed-admin' => [
				'label' => __( 'Password changed — admin', 'core-blueprint' ),
				'description' => __( 'Notification sent to the site administrator when a user password changes.', 'core-blueprint' ),
				'subject' => __( 'Password changed for {{user.login}} on {{site.name}}', 'core-blueprint' ),
				'heading' => __( 'User password changed', 'core-blueprint' ),
				'body' => __( 'The password for user {{user.login}} ({{user.email}}) was changed on {{site.name}}.', 'core-blueprint' ),
			],
		];
	}

	private function __construct() {}
}
