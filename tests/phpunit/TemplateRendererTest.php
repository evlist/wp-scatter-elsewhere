<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Metadata\PostData;
use WP_Scatter_Elsewhere\Metadata\TemplateParser;
use WP_Scatter_Elsewhere\Metadata\TemplateRenderer;

class TemplateRendererTest extends TestCase {

	private function renderer(): TemplateRenderer {
		return new TemplateRenderer(
			new TemplateParser(),
			static fn( DateTimeImmutable $date, ?string $format ): string => $date->format( $format ?? 'Y-m-d' )
		);
	}

	private function post( array $terms = [] ): PostData {
		return new PostData(
			'Tour du <em>Mont</em> Blanc &amp; plus',
			"<p>Une belle   randonn&eacute;e.</p>\n",
			'https://example.org/tour/',
			new DateTimeImmutable( '2026-06-01 10:00:00' ),
			'Eric',
			$terms
		);
	}

	public function test_renders_the_example_description(): void {
		$text = $this->renderer()->render( '{date:j F Y}, {excerpt} Détails : {permalink}', $this->post() );

		$this->assertSame( '1 June 2026, Une belle randonnée. Détails : https://example.org/tour/', $text );
	}

	public function test_title_and_author_are_plain_text(): void {
		$this->assertSame( 'Tour du Mont Blanc & plus', $this->renderer()->render( '{title}', $this->post() ) );
		$this->assertSame( 'Eric', $this->renderer()->render( '{author}', $this->post() ) );
	}

	public function test_date_without_argument_uses_the_default_format(): void {
		$this->assertSame( '2026-06-01', $this->renderer()->render( '{date}', $this->post() ) );
	}

	public function test_doubled_braces_render_as_literal_braces(): void {
		$this->assertSame( '{title} = Tour du Mont Blanc & plus', $this->renderer()->render( '{{title}} = {title}', $this->post() ) );
	}

	public function test_renders_term_lists(): void {
		$post = $this->post(
			[
				'category' => [ 'Randonnée', 'Alpes' ],
				'post_tag' => [ 'été' ],
				'genre'    => [ 'Voyage &amp; nature' ],
			]
		);

		$this->assertSame( 'Randonnée, Alpes', $this->renderer()->render( '{categories}', $post ) );
		$this->assertSame( 'été', $this->renderer()->render( '{tags}', $post ) );
		$this->assertSame( 'Voyage & nature', $this->renderer()->render( '{terms:genre}', $post ) );
		$this->assertSame( '', $this->renderer()->render( '{terms:unknown}', $post ) );
	}

	public function test_unknown_placeholders_are_left_unchanged(): void {
		$this->assertSame( 'a {foo:bar} b', $this->renderer()->render( 'a {foo:bar} b', $this->post() ) );
	}

	public function test_invalid_syntax_raises_an_exception(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->renderer()->render( '{title', $this->post() );
	}
}
