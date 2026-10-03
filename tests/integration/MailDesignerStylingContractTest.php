<?php
declare(strict_types=1);

use CoreBlueprint\Core\Design\Profile\Mail\HtmlRenderer;
use CoreBlueprint\Core\Mail\Designer\TemplateRegistry;
use CoreBlueprint\Core\Mail\Designer\TemplateRepository;

final class CB_Mail_Designer_Styling_Contract_Test extends WP_UnitTestCase {
	private mixed $original_overrides;

	public function set_up(): void {
		parent::set_up();
		$this->original_overrides = get_option( TemplateRepository::OPTION, null );
		TemplateRegistry::_reset_for_testing();
	}

	public function tear_down(): void {
		if ( null === $this->original_overrides ) {
			delete_option( TemplateRepository::OPTION );
		} else {
			update_option( TemplateRepository::OPTION, $this->original_overrides, false );
		}
		TemplateRegistry::_reset_for_testing();
		parent::tear_down();
	}

	public function test_global_and_section_styles_survive_save_reload_and_render(): void {
		$template_id = 'wordpress.password-reset';
		$definition = TemplateRegistry::get( $template_id );
		self::assertIsArray( $definition );

		$project = $definition['project'];
		self::assertIsArray( $project );

		$project['root']['properties']['preheader'] = 'Styled preheader';
		$project['root']['properties']['layout'] = [
			'width'             => 640,
			'background'        => '#101820',
			'contentBackground' => '#fefefe',
			'fontFamily'        => 'Georgia, Times New Roman, serif',
			'textColor'         => '#112233',
			'accentColor'       => '#445566',
		];
		$project['root']['children'][0]['properties']['background'] = '#ddeeff';
		$project['root']['children'][0]['properties']['padding'] = 44;

		self::assertTrue( TemplateRepository::save( $template_id, 'Styled subject', $project ) );

		$stored = TemplateRepository::get( $template_id );
		self::assertIsArray( $stored );
		self::assertSame( 'Styled subject', $stored['subject'] );
		self::assertSame( 'Styled preheader', $stored['project']['root']['properties']['preheader'] );
		self::assertSame( 640, $stored['project']['root']['properties']['layout']['width'] );
		self::assertSame( 'Georgia, Times New Roman, serif', $stored['project']['root']['properties']['layout']['fontFamily'] );
		self::assertSame( '#ddeeff', $stored['project']['root']['children'][0]['properties']['background'] );
		self::assertSame( 44, $stored['project']['root']['children'][0]['properties']['padding'] );

		$html = ( new HtmlRenderer() )->render( $stored['project'] );
		self::assertStringContainsString( 'Styled preheader', $html );
		self::assertStringContainsString( 'width:640px', $html );
		self::assertStringContainsString( 'background:#101820', $html );
		self::assertStringContainsString( 'background:#fefefe', $html );
		self::assertStringContainsString( 'font-family:Georgia, Times New Roman, serif', $html );
		self::assertStringContainsString( 'color:#112233', $html );
		self::assertStringContainsString( 'background:#ddeeff', $html );
		self::assertStringContainsString( 'padding:44px', $html );
	}
}
