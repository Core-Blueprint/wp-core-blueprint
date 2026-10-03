<?php
declare(strict_types=1);
/**
 * Base-owned WordPress system mail template definitions.
 *
 * These are presentation defaults only. WordPress remains authoritative for
 * notification lifecycle, recipients, security keys and action URLs.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Mail\Designer;

defined( 'ABSPATH' ) || exit;

final class WordPressTemplates {
	public static function register(): void {
		self::add(
			'wordpress.password-reset',
			__( 'Password reset', 'core-blueprint' ),
			__( 'Password reset email sent to a user.', 'core-blueprint' ),
			__( 'Reset your password for {{site.name}}', 'core-blueprint' ),
			__( 'Reset your password', 'core-blueprint' ),
			__( "Hi {{user.display_name}},\n\nWe received a request to reset the password for your account. If this was you, use the button below. If not, you can ignore this email.", 'core-blueprint' ),
			__( 'Reset password', 'core-blueprint' ),
			'{{action.url}}'
		);

		self::add(
			'wordpress.new-user',
			__( 'New user — user', 'core-blueprint' ),
			__( 'Welcome email sent to a newly created WordPress user.', 'core-blueprint' ),
			__( 'Your account at {{site.name}}', 'core-blueprint' ),
			__( 'Welcome to {{site.name}}', 'core-blueprint' ),
			__( "Hi {{user.display_name}},\n\nAn account has been created for you. Your username is {{user.login}}. Use the button below to set your password and sign in.", 'core-blueprint' ),
			__( 'Set your password', 'core-blueprint' ),
			'{{action.url}}'
		);

		self::add(
			'wordpress.new-user-admin',
			__( 'New user — admin', 'core-blueprint' ),
			__( 'Notification sent to the site administrator when a user is created.', 'core-blueprint' ),
			__( 'New user registration on {{site.name}}', 'core-blueprint' ),
			__( 'New user registration', 'core-blueprint' ),
			__( "A new user has been registered on {{site.name}}.\n\nUsername: {{user.login}}\nEmail: {{user.email}}", 'core-blueprint' )
		);

		self::add(
			'wordpress.password-changed',
			__( 'Password changed — user', 'core-blueprint' ),
			__( 'Security notification sent when a user password changes.', 'core-blueprint' ),
			__( 'Your password was changed on {{site.name}}', 'core-blueprint' ),
			__( 'Your password was changed', 'core-blueprint' ),
			__( "Hi {{user.display_name}},\n\nThe password for your account {{user.login}} was changed. If you did not make this change, contact the site administrator at {{site.admin_email}}.", 'core-blueprint' )
		);

		self::add(
			'wordpress.email-changed',
			__( 'Email changed — user', 'core-blueprint' ),
			__( 'Security notification sent to the previous address after an email change.', 'core-blueprint' ),
			__( 'Your email address was changed on {{site.name}}', 'core-blueprint' ),
			__( 'Your email address was changed', 'core-blueprint' ),
			__( "Hi {{user.display_name}},\n\nThe email address for your account {{user.login}} was changed. If you did not make this change, contact the site administrator at {{site.admin_email}}.", 'core-blueprint' )
		);

		self::add(
			'wordpress.password-changed-admin',
			__( 'Password changed — admin', 'core-blueprint' ),
			__( 'Notification sent to the site administrator when a user password changes.', 'core-blueprint' ),
			__( 'Password changed for {{user.login}} on {{site.name}}', 'core-blueprint' ),
			__( 'User password changed', 'core-blueprint' ),
			__( 'The password for user {{user.login}} ({{user.email}}) was changed on {{site.name}}.', 'core-blueprint' )
		);
	}

	private static function add(
		string $id,
		string $label,
		string $description,
		string $subject,
		string $heading,
		string $body,
		string $button_label = '',
		string $button_url = ''
	): void {
		$children = [
			self::node( 'mail.heading', [ 'text' => $heading, 'fontSize' => 28, 'spacing' => 16 ] ),
			self::node( 'mail.text', [ 'text' => $body, 'fontSize' => 16, 'spacing' => 20 ] ),
		];
		if ( '' !== $button_label && '' !== $button_url ) {
			$children[] = self::node( 'mail.button', [
				'label' => $button_label,
				'url' => $button_url,
				'align' => 'left',
				'background' => '#2563eb',
				'color' => '#ffffff',
				'radius' => 6,
				'spacing' => 20,
			] );
		}
		$children[] = self::node( 'mail.divider', [ 'color' => '#e5e7eb', 'spacing' => 18 ] );
		$children[] = self::node( 'mail.text', [
			'text' => __( 'Sent by {{site.name}} — {{site.url}}', 'core-blueprint' ),
			'fontSize' => 13,
			'color' => '#6b7280',
			'spacing' => 0,
		] );

		TemplateRegistry::register( [
			'id'          => $id,
			'provider'    => 'core',
			'group'       => 'wordpress',
			'label'       => $label,
			'description' => $description,
			'subject'     => $subject,
			'project'     => self::project( $children ),
		] );
	}

	/** @param list<array<string,mixed>> $children @return array<string,mixed> */
	private static function project( array $children ): array {
		return [
			'schema_version' => 0,
			'design_type'    => 'mail-template',
			'root' => [
				'type'       => 'mail.root',
				'provider'   => 'core',
				'properties' => [
					'preheader' => __( 'A message from {{site.name}}', 'core-blueprint' ),
					'layout' => [
						'width' => 600,
						'background' => '#f3f4f6',
						'contentBackground' => '#ffffff',
						'fontFamily' => 'Arial, Helvetica, sans-serif',
						'textColor' => '#1f2937',
						'accentColor' => '#2563eb',
					],
				],
				'children' => [ [
					'type'       => 'mail.section',
					'provider'   => 'core',
					'properties' => [ 'background' => '#ffffff', 'padding' => 32 ],
					'children'   => $children,
				] ],
			],
		];
	}

	/** @param array<string,mixed> $properties @return array<string,mixed> */
	private static function node( string $type, array $properties ): array {
		return [
			'type'       => $type,
			'provider'   => 'core',
			'properties' => $properties,
			'children'   => [],
		];
	}

	private function __construct() {}
}
