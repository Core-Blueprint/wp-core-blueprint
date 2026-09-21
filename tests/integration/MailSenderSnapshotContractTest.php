<?php
declare(strict_types=1);

use CB\Core\Mail\Message;
use CB\Core\Mail\Sender;
use CB\Core\Mail\SenderContext;
use CB\Core\Mail\SenderIdentityRegistry;

final class CB_Mail_Sender_Snapshot_Contract_Test extends WP_UnitTestCase {

	private const ID = 'acme-snapshot-mail';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		SenderIdentityRegistry::register( [
			'id'            => self::ID,
			'label'         => 'Snapshot Mail',
			'description'   => 'Durable sender snapshot fixture.',
			'default_email' => 'snapshot@example.com',
			'default_name'  => 'Snapshot Sender',
		] );
	}

	public function test_matching_snapshot_is_held_for_entire_wp_mail_call(): void {
		$observed = [];
		$filter = static function ( mixed $return, array $atts ) use ( &$observed ): bool {
			$observed['identity'] = SenderIdentityRegistry::current();
			$observed['headers'] = $atts['headers'] ?? [];
			$observed['message'] = Message::from_atts( $atts );
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );

		try {
			$result = Sender::send_if_identity_matches(
				self::ID,
				'snapshot@example.com',
				'Snapshot Sender',
				'recipient@example.com',
				'Subject',
				'<p>Body</p>',
				[ 'Content-Type: text/html; charset=UTF-8' ]
			);
		} finally {
			remove_filter( 'pre_wp_mail', $filter, 10 );
		}

		self::assertTrue( $result );
		self::assertSame( 'snapshot@example.com', $observed['identity']['email'] ?? '' );
		self::assertSame( 'Snapshot Sender', $observed['identity']['name'] ?? '' );
		self::assertContains( 'From: Snapshot Sender <snapshot@example.com>', (array) ( $observed['headers'] ?? [] ) );
		self::assertSame( 'snapshot@example.com', $observed['message']['from_email'] ?? '' );
		self::assertSame( 'Snapshot Sender', $observed['message']['from_name'] ?? '' );
		self::assertSame( '', SenderContext::current() );
		self::assertNull( SenderContext::current_resolved() );
	}

	public function test_changed_snapshot_fails_before_wp_mail(): void {
		$calls = 0;
		$filter = static function () use ( &$calls ): bool {
			$calls++;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter );

		try {
			$result = Sender::send_if_identity_matches(
				self::ID,
				'changed@example.com',
				'Snapshot Sender',
				'recipient@example.com',
				'Subject',
				'Body'
			);
		} finally {
			remove_filter( 'pre_wp_mail', $filter );
		}

		self::assertWPError( $result );
		self::assertSame( 'cb_core_mail_sender_identity_changed', $result->get_error_code() );
		self::assertSame( 0, $calls );
	}

	public function test_unknown_identity_fails_instead_of_using_default_sender(): void {
		$result = Sender::send_if_identity_matches(
			'unknown-snapshot-mail',
			'snapshot@example.com',
			'Snapshot Sender',
			'recipient@example.com',
			'Subject',
			'Body'
		);

		self::assertWPError( $result );
		self::assertSame( 'cb_core_mail_sender_identity_unavailable', $result->get_error_code() );
	}

	public function test_resolved_context_rejects_arbitrary_identity_injection(): void {
		$accepted = SenderContext::push_resolved(
			self::ID,
			[
				'id'    => self::ID,
				'email' => 'spoofed@example.com',
				'name'  => 'Spoofed',
			]
		);

		self::assertFalse( $accepted );
		self::assertSame( '', SenderContext::current() );
		self::assertNull( SenderContext::current_resolved() );
	}
}
