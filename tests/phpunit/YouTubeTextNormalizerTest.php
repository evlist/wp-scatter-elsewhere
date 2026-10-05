<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Metadata\YouTubeTextNormalizer;

class YouTubeTextNormalizerTest extends TestCase {

	public function test_title_loses_angle_brackets_and_newlines(): void {
		$title = ( new YouTubeTextNormalizer() )->normalizeTitle( "  A <b>bold</b>\n title  " );

		$this->assertSame( 'A bbold/b title', $title );
	}

	public function test_long_title_is_cut_with_an_ellipsis(): void {
		$title = ( new YouTubeTextNormalizer() )->normalizeTitle( str_repeat( 'é', 150 ) );

		$this->assertSame( 100, mb_strlen( $title, 'UTF-8' ) );
		$this->assertSame( '…', mb_substr( $title, -1, null, 'UTF-8' ) );
	}

	public function test_title_of_exactly_the_limit_is_kept(): void {
		$title = str_repeat( 'a', 100 );

		$this->assertSame( $title, ( new YouTubeTextNormalizer() )->normalizeTitle( $title ) );
	}

	public function test_empty_title_falls_back_then_to_generic_text(): void {
		$normalizer = new YouTubeTextNormalizer();

		$this->assertSame( 'Post title', $normalizer->normalizeTitle( '  ', 'Post title' ) );
		$this->assertSame( 'Untitled video', $normalizer->normalizeTitle( '<>', '' ) );
	}

	public function test_description_loses_angle_brackets_and_normalizes_line_endings(): void {
		$text = ( new YouTubeTextNormalizer() )->normalizeDescription( "a <b>\r\n\r\nhttps://example.org/?x=1>" );

		$this->assertSame( "a b\n\nhttps://example.org/?x=1", $text );
	}

	public function test_long_description_is_cut_without_splitting_a_character(): void {
		$text = ( new YouTubeTextNormalizer() )->normalizeDescription( str_repeat( 'é', 3000 ) );

		$this->assertLessThanOrEqual( 5000, strlen( $text ) );
		$this->assertTrue( mb_check_encoding( $text, 'UTF-8' ) );
		$this->assertSame( 2500, mb_strlen( $text, 'UTF-8' ) );
	}
}
