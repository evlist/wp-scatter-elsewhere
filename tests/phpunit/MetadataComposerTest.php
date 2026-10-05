<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Everywhere\Metadata\MetadataComposer;
use WP_Scatter_Everywhere\Metadata\PostData;
use WP_Scatter_Everywhere\Metadata\TemplateParser;
use WP_Scatter_Everywhere\Metadata\TemplateRenderer;
use WP_Scatter_Everywhere\Metadata\YouTubeTextNormalizer;
use WP_Scatter_Everywhere\Settings\MetadataTemplateSettings;

class MetadataComposerTest extends TestCase {

	private function composer( mixed $stored ): MetadataComposer {
		$parser = new TemplateParser();

		return new MetadataComposer(
			new MetadataTemplateSettings( static fn(): mixed => $stored, static function ( array $value ): void {}, $parser ),
			new TemplateRenderer( $parser, static fn( DateTimeImmutable $date, ?string $format ): string => $date->format( $format ?? 'Y-m-d' ) ),
			new YouTubeTextNormalizer()
		);
	}

	private function post( string $title = 'Tour du Mont Blanc' ): PostData {
		return new PostData( $title, 'Une belle randonnée.', 'https://example.org/tour/', new DateTimeImmutable( '2026-06-01' ), 'Eric' );
	}

	public function test_default_templates(): void {
		$metadata = $this->composer( false )->compose( $this->post() );

		$this->assertSame( 'Tour du Mont Blanc', $metadata['title'] );
		$this->assertSame( "Une belle randonnée.\n\nhttps://example.org/tour/", $metadata['description'] );
	}

	public function test_custom_templates(): void {
		$metadata = $this->composer(
			[ 'title' => '[{date:Y}] {title}', 'description' => '{date:d/m/Y}, {excerpt} Détails : {permalink}' ]
		)->compose( $this->post() );

		$this->assertSame( '[2026] Tour du Mont Blanc', $metadata['title'] );
		$this->assertSame( '01/06/2026, Une belle randonnée. Détails : https://example.org/tour/', $metadata['description'] );
	}

	public function test_empty_rendered_title_falls_back_to_the_post_title(): void {
		$metadata = $this->composer( [ 'title' => '{terms:genre}' ] )->compose( $this->post() );

		$this->assertSame( 'Tour du Mont Blanc', $metadata['title'] );
	}
}
