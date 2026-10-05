<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Everywhere\Metadata\Placeholder;
use WP_Scatter_Everywhere\Metadata\TemplateParser;

class TemplateParserTest extends TestCase {

	public function test_splits_text_and_placeholders(): void {
		$nodes = ( new TemplateParser() )->parse( 'On {date:j F Y}: {title}!' );

		$this->assertCount( 5, $nodes );
		$this->assertSame( 'On ', $nodes[0] );
		$this->assertInstanceOf( Placeholder::class, $nodes[1] );
		$this->assertSame( 'date', $nodes[1]->name );
		$this->assertSame( 'j F Y', $nodes[1]->argument );
		$this->assertSame( ': ', $nodes[2] );
		$this->assertSame( 'title', $nodes[3]->name );
		$this->assertNull( $nodes[3]->argument );
		$this->assertSame( '!', $nodes[4] );
	}

	public function test_doubled_braces_are_literal(): void {
		$nodes = ( new TemplateParser() )->parse( '{{title}}' );

		$this->assertSame( [ '{title}' ], $nodes );
	}

	public function test_rejects_unbalanced_opening_brace(): void {
		$this->assertNotNull( ( new TemplateParser() )->validate( 'Hello {title' ) );
		$this->assertNotNull( ( new TemplateParser() )->validate( 'Hello {ti{tle}' ) );
	}

	public function test_rejects_unbalanced_closing_brace(): void {
		$this->assertNotNull( ( new TemplateParser() )->validate( 'Hello title}' ) );
	}

	public function test_rejects_malformed_placeholder(): void {
		$this->assertNotNull( ( new TemplateParser() )->validate( '{}' ) );
		$this->assertNotNull( ( new TemplateParser() )->validate( '{Title}' ) );
	}

	public function test_rejects_unknown_placeholder_naming_it(): void {
		$error = ( new TemplateParser() )->validate( 'x {foo} y' );

		$this->assertStringContainsString( '{foo}', (string) $error );
	}

	public function test_rejects_argument_on_placeholder_without_argument(): void {
		$this->assertNotNull( ( new TemplateParser() )->validate( '{title:x}' ) );
	}

	public function test_requires_argument_for_terms_and_non_empty_date_argument(): void {
		$parser = new TemplateParser();

		$this->assertNotNull( $parser->validate( '{terms}' ) );
		$this->assertNotNull( $parser->validate( '{terms:}' ) );
		$this->assertNotNull( $parser->validate( '{date:}' ) );
	}

	public function test_accepts_valid_templates(): void {
		$parser = new TemplateParser();

		$this->assertNull( $parser->validate( '' ) );
		$this->assertNull( $parser->validate( "{date}, {excerpt}\n\n{permalink} {author} {categories} {tags} {terms:genre}" ) );
	}
}
