<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Metadata\PostData;
use WP_Scatter_Elsewhere\Metadata\SectionExtractor;
use WP_Scatter_Elsewhere\Metadata\TemplateParser;
use WP_Scatter_Elsewhere\Metadata\TemplateRenderer;

class SectionExtractorTest extends TestCase {

	private const HTML = <<<'HTML'
<h1>Salers ⇾ Pérols</h1>
<h2>🗺️ Le Topoguide</h2>
<p>Les données GPS du 8 octobre.</p>
<h3>Résumé technique</h3>
<ul><li>338 km</li><li>6 h 28</li></ul>
<p>Download file: <a href="x.gpx">x.gpx</a></p>
<h2>✍️ Le Carnet de voyage</h2>
<p><em>Une dernière étape entre Salers et Pérols.</em></p>
<p>Il avait plu &amp; venté<br>toute la nuit.</p>
<blockquote><p>Une citation.</p></blockquote>
<h3>Détail</h3>
<p>Un sous-titre.</p>
<h2>💡 Pour les curieux</h2>
<p>Autre chose.</p>
HTML;

	public function test_the_section_goes_to_the_next_heading_of_the_same_or_a_higher_level(): void {
		$text = ( new SectionExtractor() )->section( self::HTML, 'Le Carnet de voyage' );

		$this->assertSame( "Une dernière étape entre Salers et Pérols.\n\nIl avait plu & venté toute la nuit.\n\nUne citation.\n\nUn sous-titre.", $text );
	}

	public function test_sub_headings_stay_in_their_section_and_the_section_stops_at_a_sibling(): void {
		$this->assertSame( "338 km\n\n6 h 28\n\nDownload file: x.gpx", ( new SectionExtractor() )->section( self::HTML, 'résumé TECHNIQUE' ) );
	}

	public function test_a_missing_heading_gives_nothing(): void {
		$this->assertSame( '', ( new SectionExtractor() )->section( self::HTML, 'Inconnu' ) );
		$this->assertSame( '', ( new SectionExtractor() )->section( '', 'Le Topoguide' ) );
		$this->assertSame( '', ( new SectionExtractor() )->section( self::HTML, '!!' ) );
	}

	public function test_the_first_paragraphs_skip_lists_and_quotes(): void {
		$this->assertSame( 'Les données GPS du 8 octobre.', ( new SectionExtractor() )->paragraphs( self::HTML, 1 ) );
		$this->assertSame( "Les données GPS du 8 octobre.\n\nDownload file: x.gpx\n\nUne dernière étape entre Salers et Pérols.", ( new SectionExtractor() )->paragraphs( self::HTML, 3 ) );
		$this->assertSame( '', ( new SectionExtractor() )->paragraphs( self::HTML, 0 ) );
	}

	public function test_the_placeholders_read_the_content_of_the_post_lazily(): void {
		$reads = 0;
		$post  = new PostData( 'Titre', 'Extrait', 'https://e.vli.st/p/', new DateTimeImmutable( '2026-10-08' ), 'Eric', [], null, [], static function () use ( &$reads ): string {
			++$reads;
			return self::HTML;
		} );
		$renderer = new TemplateRenderer( new TemplateParser(), static fn( DateTimeImmutable $d, ?string $f ): string => $d->format( $f ?? 'Y-m-d' ) );

		$this->assertSame( 'Extrait', $renderer->render( '{excerpt}', $post ) );
		$this->assertSame( 0, $reads, 'The content is not read when no placeholder needs it.' );

		$this->assertStringStartsWith( 'Une dernière étape entre Salers et Pérols.', $renderer->render( '{section:Le Carnet de voyage}', $post ) );
		$this->assertSame( 'Les données GPS du 8 octobre.', $renderer->render( '{paragraphs:1}', $post ) );
		$this->assertSame( 1, $reads, 'It is read once.' );
		$this->assertNull( ( new TemplateParser() )->validate( '{section:Le Carnet de voyage} {paragraphs:2}' ) );
		$this->assertNotNull( ( new TemplateParser() )->validate( '{section}' ) );
	}
}
