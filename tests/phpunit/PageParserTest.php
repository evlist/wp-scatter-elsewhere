<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Everywhere\Detection\PageParser;
use WP_Scatter_Everywhere\Detection\UrlResolver;

class PageParserTest extends TestCase {

	private function parse( string $html, string $url = 'https://example.org/post/' ): array {
		return ( new PageParser( new UrlResolver() ) )->parse( $html, $url );
	}

	public function test_parses_the_page_of_the_blog(): void {
		$html   = (string) file_get_contents( __DIR__ . '/../fixtures/grenoble-salers.html' );
		$videos = $this->parse( $html, 'https://e.vli.st/2026/10/05/grenoble-%e2%87%be-salers/' );

		$this->assertCount( 1, $videos );
		$this->assertSame( [ 'https://e.vli.st/wp-content/uploads/photos/2026/eric/10/05/20261005.mp4' ], $videos[0]->sources );
		$this->assertSame(
			[
				[
					'url'      => 'https://e.vli.st/wp-content/uploads/photos/2026/eric/10/05/20261005-fr.vtt',
					'language' => 'fr',
					'label'    => 'FR',
				],
			],
			$videos[0]->tracks
		);
		$this->assertNull( $videos[0]->poster );
	}

	public function test_collects_sources_and_tracks_of_each_video(): void {
		$videos = $this->parse(
			'<video poster="p.jpg"><source src="a.webm" type="video/webm"><source src="a.mp4" type="video/mp4">'
			. '<track kind="subtitles" src="a-fr.vtt" srclang="FR" label="Français">'
			. '<track kind="captions" src="a-en.vtt" srclang="en">'
			. '<track src="a-de.vtt" srclang="de"></video>'
			. '<video src="b.mp4"></video>'
		);

		$this->assertCount( 2, $videos );
		$this->assertSame( [ 'https://example.org/post/a.webm', 'https://example.org/post/a.mp4' ], $videos[0]->sources );
		$this->assertSame( 'https://example.org/post/p.jpg', $videos[0]->poster );
		$this->assertSame( [ 'fr', 'en', 'de' ], array_column( $videos[0]->tracks, 'language' ) );
		$this->assertSame( 'Français', $videos[0]->tracks[0]['label'] );
		$this->assertNull( $videos[0]->tracks[1]['label'] );
		$this->assertSame( [ 'https://example.org/post/b.mp4' ], $videos[1]->sources );
		$this->assertSame( [], $videos[1]->tracks );
	}

	public function test_ignores_tracks_that_are_not_subtitles(): void {
		$videos = $this->parse(
			'<video src="a.mp4"><track kind="chapters" src="c.vtt" srclang="fr"><track kind="metadata" src="m.vtt">'
			. '<track kind="descriptions" src="d.vtt" srclang="fr"><track kind="subtitles" src="s.vtt"></video>'
		);

		$this->assertCount( 1, $videos[0]->tracks );
		$this->assertSame( 'https://example.org/post/s.vtt', $videos[0]->tracks[0]['url'] );
		$this->assertNull( $videos[0]->tracks[0]['language'] );
	}

	public function test_honours_the_base_element(): void {
		$videos = $this->parse( '<html><head><base href="/media/"></head><body><video src="a.mp4"></video></body></html>' );

		$this->assertSame( [ 'https://example.org/media/a.mp4' ], $videos[0]->sources );
	}

	public function test_ignores_videos_without_source_and_tolerates_malformed_html(): void {
		$videos = $this->parse( '<div><video controls></video><p>unclosed <video src="a.mp4"><b></div>' );

		$this->assertCount( 1, $videos );
		$this->assertSame( [ 'https://example.org/post/a.mp4' ], $videos[0]->sources );
	}

	public function test_page_without_video(): void {
		$this->assertSame( [], $this->parse( '<p>Hello</p>' ) );
		$this->assertSame( [], $this->parse( '' ) );
	}
}
