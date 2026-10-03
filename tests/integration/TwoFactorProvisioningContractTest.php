<?php
declare(strict_types=1);

use CoreBlueprint\Core\Security\TwoFactor\Provisioning;

final class CB_Base_Two_Factor_Provisioning_Contract_Test extends WP_UnitTestCase {

	private string $original_blogname = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_blogname = (string) get_option( 'blogname', '' );
	}

	public function tear_down(): void {
		update_option( 'blogname', $this->original_blogname );
		parent::tear_down();
	}

	public function test_tp1_uri_uses_standard_totp_parameters_and_rfc3986_encoding(): void {
		update_option( 'blogname', 'ACME & Site: Dev' );
		$user_id = self::factory()->user->create( [
			'role'       => 'administrator',
			'user_login' => 'operator.user',
		] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		$uri = Provisioning::uri( $user, 'JBSWY3DPEHPK3PXP' );

		self::assertSame(
			'otpauth://totp/Core%20Blueprint:ACME%20%26%20Site%3A%20Dev%20%28operator.user%29'
			. '?secret=JBSWY3DPEHPK3PXP'
			. '&issuer=Core%20Blueprint'
			. '&algorithm=SHA1'
			. '&digits=6'
			. '&period=30',
			$uri
		);
		self::assertStringNotContainsString( '%26amp%3B', $uri );
	}

	public function test_tp2_uri_rejects_invalid_secret_material(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		$this->expectException( \InvalidArgumentException::class );
		Provisioning::uri( $user, 'not a base32 secret!' );
	}

	public function test_tp3_empty_site_name_falls_back_without_changing_totp_parameters(): void {
		update_option( 'blogname', '' );
		$user_id = self::factory()->user->create( [
			'role'       => 'administrator',
			'user_login' => 'operator',
		] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		$uri = Provisioning::uri( $user, 'JBSWY3DPEHPK3PXP' );

		self::assertStringStartsWith( 'otpauth://totp/Core%20Blueprint:', $uri );
		self::assertStringContainsString( '%28operator%29?secret=JBSWY3DPEHPK3PXP', $uri );
		self::assertStringContainsString( '&algorithm=SHA1&digits=6&period=30', $uri );
	}
}
