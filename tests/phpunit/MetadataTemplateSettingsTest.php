<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Metadata\TemplateParser;
use WP_Scatter_Elsewhere\Settings\MetadataTemplateSettings;

class MetadataTemplateSettingsTest extends TestCase {

	private function settings( mixed $stored, ?array &$saved = null ): MetadataTemplateSettings {
		return new MetadataTemplateSettings(
			static fn(): mixed => $stored,
			static function ( array $value ) use ( &$saved ): void {
				$saved = $value;
			},
			new TemplateParser()
		);
	}

	public function test_defaults_are_used_when_nothing_is_stored(): void {
		$settings = $this->settings( false );

		$this->assertSame( '{title}', $settings->getTitleTemplate() );
		$this->assertSame( "{excerpt}\n\n{permalink}", $settings->getDescriptionTemplate() );
	}

	public function test_defaults_are_used_for_empty_or_malformed_values(): void {
		$this->assertSame( '{title}', $this->settings( [ 'title' => '  ' ] )->getTitleTemplate() );
		$this->assertSame( '{title}', $this->settings( 'garbage' )->getTitleTemplate() );
	}

	public function test_stored_templates_are_returned(): void {
		$settings = $this->settings( [ 'title' => 'Video: {title}', 'description' => '{date:Y}' ] );

		$this->assertSame( 'Video: {title}', $settings->getTitleTemplate() );
		$this->assertSame( '{date:Y}', $settings->getDescriptionTemplate() );
	}

	public function test_saves_normalized_templates(): void {
		$saved = null;
		$this->settings( [], $saved )->save(
			[
				'title'       => "  Video\n{title}  ",
				'description' => "  {excerpt}\r\n\r\n{permalink}  ",
			]
		);

		$this->assertSame(
			[
				'title'       => 'Video {title}',
				'description' => "{excerpt}\n\n{permalink}",
			],
			$saved
		);
	}

	public function test_invalid_template_is_not_saved_and_names_the_field(): void {
		$saved    = null;
		$settings = $this->settings( [], $saved );
		$templates = [ 'title' => '{title}', 'description' => '{nope}' ];

		$this->assertArrayHasKey( 'description', $settings->validate( $templates ) );
		$this->assertArrayNotHasKey( 'title', $settings->validate( $templates ) );

		try {
			$settings->save( $templates );
			$this->fail( 'Expected an InvalidArgumentException.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( '{nope}', $e->getMessage() );
		}

		$this->assertNull( $saved );
	}
}
