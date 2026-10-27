<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Editor\PlatformTransport;
use CoreBlueprint\Core\Design\Profile\Mail\HtmlRenderer;
use CoreBlueprint\Core\Mail\Designer\TemplateRegistry;
use CoreBlueprint\Core\Mail\Designer\TemplateRepository;
use CoreBlueprint\Core\Mail\Designer\WordPressTemplates;

final class CB_Mail_Designer_Styling_Contract_Test extends WP_UnitTestCase {
	public function test_native_styles_subject_and_preheader_render_without_legacy_write_authority(): void {
		$template_id = 'wordpress.password-reset';
		$definition = TemplateRegistry::get( $template_id );
		self::assertIsArray( $definition );
		$native = WordPressTemplates::default_document( $template_id );
		self::assertIsArray( $native );
		$native['fields']['subject'] = 'Styled subject';
		$native['fields']['preheader'] = 'Styled preheader';
		$native['project']['root']['properties'] = array_replace(
			$native['project']['root']['properties'],
			[
				'width' => 640,
				'background' => '#101820',
				'contentBackground' => '#fefefe',
				'fontFamily' => 'Georgia, Times New Roman, serif',
				'textColor' => '#112233',
				'accentColor' => '#445566',
			]
		);
		$native['project']['root']['children'][0]['properties']['background'] = '#ddeeff';
		$native['project']['root']['children'][0]['properties']['padding'] = 44;

		self::assertTrue( PlatformTransport::valid_native_document( Repository::MAIL, $native ) );
		$html = ( new HtmlRenderer() )->render( $native['project'], [
			'action.url' => 'https://example.test/reset-password',
		], [
			'preheader' => $native['fields']['preheader'],
		] );
		self::assertStringContainsString( 'Styled preheader', $html );
		self::assertStringContainsString( 'href="https://example.test/reset-password"', $html );
		self::assertStringContainsString( 'width:640px', $html );
		self::assertStringContainsString( 'background:#101820', $html );
		self::assertStringContainsString( 'background:#fefefe', $html );
		self::assertStringContainsString( 'font-family:Georgia, Times New Roman, serif', $html );
		self::assertStringContainsString( 'color:#112233', $html );
		self::assertStringContainsString( 'background:#ddeeff', $html );
		self::assertStringContainsString( 'padding:44px', $html );

		$before = Repository::get( Repository::MAIL, $template_id );
		self::assertFalse( TemplateRepository::save( $template_id, $native['fields']['subject'], $native['project'] ) );
		self::assertSame( 'blocked', TemplateRepository::mutate(
			$template_id, '0', $native['fields']['subject'], $native['project']
		)['status'] );
		self::assertSame( $before, Repository::get( Repository::MAIL, $template_id ) );
	}

	public function test_invalid_native_styling_and_extra_fields_fail_closed(): void {
		$native = WordPressTemplates::default_document( 'wordpress.password-reset' );
		self::assertIsArray( $native );
		$native['project']['root']['properties']['background'] = 'javascript:alert(1)';
		self::assertFalse( PlatformTransport::valid_native_document( Repository::MAIL, $native ) );
		$this->expectException( \InvalidArgumentException::class );
		( new HtmlRenderer() )->render( $native['project'] );
	}

	public function test_subject_preheader_and_legacy_option_cannot_change_native_defaults(): void {
		$id = 'wordpress.password-reset';
		$native = WordPressTemplates::default_document( $id );
		self::assertIsArray( $native );
		$invalid = $native;
		$invalid['fields']['preheader'] = str_repeat( 'a', 501 );
		self::assertFalse( PlatformTransport::valid_native_document( Repository::MAIL, $invalid ) );
		$invalid = $native;
		$invalid['fields']['subject'] = '';
		self::assertFalse( PlatformTransport::valid_native_document( Repository::MAIL, $invalid ) );
		update_option( TemplateRepository::OPTION, [
			$id => [ 'subject' => 'Legacy override', 'project' => $native['project'] ],
		], false );
		self::assertSame( [], TemplateRepository::overrides() );
		$loaded = TemplateRepository::get( $id );
		self::assertIsArray( $loaded );
		self::assertNotSame( 'Legacy override', $loaded['subject'] );
		self::assertSame( 'native', $loaded['authority'] );
		self::assertTrue( Repository::valid_revision( (string) $loaded['revision'] ) );
	}
}
