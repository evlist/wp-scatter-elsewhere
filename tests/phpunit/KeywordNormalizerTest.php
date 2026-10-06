<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Metadata\KeywordNormalizer;

class KeywordNormalizerTest extends TestCase {

	public function test_cleans_deduplicates_and_keeps_the_order(): void {
		$result = ( new KeywordNormalizer() )->fit( [ ' vanlife ', 'Salers', 'salers', '<b>x</b>', '', '  ', 'a   b' ] );

		$this->assertSame( [ 'vanlife', 'Salers', 'bx/b', 'a b' ], $result['kept'] );
		$this->assertSame( [], $result['dropped'] );
	}

	public function test_counts_quotes_around_keywords_with_spaces_and_the_separators(): void {
		$normalizer = new KeywordNormalizer();

		$this->assertSame( 0, $normalizer->length( [] ) );
		$this->assertSame( 5, $normalizer->length( [ 'salers' ] ) - 1 );
		$this->assertSame( 6 + 1 + ( 3 + 2 ), $normalizer->length( [ 'salers', 'a b' ] ) );
	}

	public function test_keywords_that_do_not_fit_are_dropped_and_later_ones_may_still_fit(): void {
		$result = ( new KeywordNormalizer() )->fit( [ str_repeat( 'x', 300 ), str_repeat( 'y', 300 ), 'ok' ] );

		$this->assertSame( [ str_repeat( 'x', 300 ), 'ok' ], $result['kept'] );
		$this->assertSame( [ str_repeat( 'y', 300 ) ], $result['dropped'] );
		$this->assertLessThanOrEqual( KeywordNormalizer::MAX_CHARACTERS, ( new KeywordNormalizer() )->length( $result['kept'] ) );
	}

	public function test_adding_keeps_the_existing_ones_and_skips_the_known_ones(): void {
		$result = ( new KeywordNormalizer() )->add( [ 'Salers', 'été' ], [ 'salers', 'vanlife', 'ÉTÉ' ] );

		$this->assertSame( [ 'Salers', 'été', 'vanlife' ], $result['kept'] );
		$this->assertSame( [], $result['dropped'] );
	}

	public function test_existing_keywords_are_never_dropped_even_above_the_limit(): void {
		$existing = [ str_repeat( 'x', 300 ), str_repeat( 'y', 300 ) ];

		$result = ( new KeywordNormalizer() )->add( $existing, [ 'new' ] );

		$this->assertSame( $existing, $result['kept'] );
		$this->assertSame( [ 'new' ], $result['dropped'] );
	}
}
