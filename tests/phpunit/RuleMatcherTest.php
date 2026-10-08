<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Metadata\PostData;
use WP_Scatter_Elsewhere\Rules\RuleMatcher;
use WP_Scatter_Elsewhere\Rules\TermRule;

class RuleMatcherTest extends TestCase {

	private function post( array $details ): PostData {
		return new PostData( 'T', 'E', 'https://example.org/p/', new DateTimeImmutable( '2026-10-05' ), 'Eric', [], null, $details );
	}

	public function test_a_rule_applies_to_a_post_that_has_its_term(): void {
		$match = ( new RuleMatcher() )->match(
			[ new TermRule( 'category', 'vanlife', 'PLaaaaaaaaaaa', 'vanlife' ), new TermRule( 'category', 'velo', 'PLbbbbbbbbbbb', 'velo' ) ],
			$this->post( [ 'category' => [ [ 'slug' => 'vanlife', 'ancestors' => [] ] ] ] )
		);

		$this->assertSame( [ 'PLaaaaaaaaaaa' ], $match->playlists );
		$this->assertSame( [ 'vanlife' ], $match->keywords );
	}

	public function test_a_term_of_another_taxonomy_does_not_match(): void {
		$match = ( new RuleMatcher() )->match(
			[ new TermRule( 'post_tag', 'vanlife', '', 'vanlife' ) ],
			$this->post( [ 'category' => [ [ 'slug' => 'vanlife', 'ancestors' => [] ] ] ] )
		);

		$this->assertSame( [], $match->keywords );
	}

	public function test_sub_terms_do_not_inherit_a_rule_by_default(): void {
		$post = $this->post( [ 'category' => [ [ 'slug' => 'randos-alpes', 'ancestors' => [ 'randos', 'voyages' ] ] ] ] );

		$match = ( new RuleMatcher() )->match( [ new TermRule( 'category', 'randos', 'PLaaaaaaaaaaa', 'randos' ) ], $post );

		$this->assertSame( [], $match->playlists );
		$this->assertSame( [], $match->keywords );
	}

	public function test_include_sub_terms_applies_a_rule_to_the_descendants_of_its_term(): void {
		$post = $this->post( [ 'category' => [ [ 'slug' => 'randos-alpes', 'ancestors' => [ 'randos', 'voyages' ] ] ] ] );

		$match = ( new RuleMatcher() )->match(
			[ new TermRule( 'category', 'voyages', 'PLaaaaaaaaaaa', 'voyages', true ), new TermRule( 'category', 'randos-alpes', '', 'alpes' ), new TermRule( 'category', 'ski', 'PLccccccccccc', 'ski', true ) ],
			$post
		);

		$this->assertSame( [ 'PLaaaaaaaaaaa' ], $match->playlists );
		$this->assertSame( [ 'voyages', 'alpes' ], $match->keywords );
	}

	public function test_playlists_and_keywords_are_collected_without_duplicates(): void {
		$post = $this->post(
			[
				'category' => [ [ 'slug' => 'a', 'ancestors' => [] ], [ 'slug' => 'b', 'ancestors' => [] ] ],
				'post_tag' => [ [ 'slug' => 'c', 'ancestors' => [] ] ],
			]
		);

		$match = ( new RuleMatcher() )->match(
			[
				new TermRule( 'category', 'a', 'PLaaaaaaaaaaa', 'Salers' ),
				new TermRule( 'category', 'b', 'PLaaaaaaaaaaa', 'salers' ),
				new TermRule( 'post_tag', 'c', 'PLbbbbbbbbbbb', 'été' ),
			],
			$post
		);

		$this->assertSame( [ 'PLaaaaaaaaaaa', 'PLbbbbbbbbbbb' ], $match->playlists );
		$this->assertSame( [ 'Salers', 'été' ], $match->keywords );
	}

	public function test_a_post_without_terms_matches_nothing(): void {
		$match = ( new RuleMatcher() )->match( [ new TermRule( 'category', 'a', 'PLaaaaaaaaaaa', 'a' ) ], $this->post( [] ) );

		$this->assertSame( [], $match->playlists );
		$this->assertSame( [], $match->keywords );
	}

	public function test_the_category_comes_from_the_most_specific_matching_term(): void {
		$post = $this->post( [ 'category' => [ [ 'slug' => 'randos-alpes', 'ancestors' => [ 'randos', 'voyages' ] ] ] ] );

		$match = ( new RuleMatcher() )->match(
			[
				new TermRule( 'category', 'voyages', '', '', true, '19' ),
				new TermRule( 'category', 'randos', '', '', true, '17' ),
				new TermRule( 'category', 'randos-alpes', '', '', false, '27' ),
			],
			$post
		);
		$this->assertSame( '27', $match->categoryId, 'The term of the post itself is the most specific.' );

		$inherited = ( new RuleMatcher() )->match(
			[ new TermRule( 'category', 'voyages', '', '', true, '19' ), new TermRule( 'category', 'randos', '', '', true, '17' ) ],
			$post
		);
		$this->assertSame( '17', $inherited->categoryId, 'A sub-term before its parent, whatever the order.' );
	}

	public function test_on_a_tie_the_first_row_gives_the_category_and_without_a_rule_there_is_none(): void {
		$post = $this->post( [ 'category' => [ [ 'slug' => 'a', 'ancestors' => [] ], [ 'slug' => 'b', 'ancestors' => [] ] ] ] );

		$tie = ( new RuleMatcher() )->match( [ new TermRule( 'category', 'b', '', '', false, '24' ), new TermRule( 'category', 'a', '', '', false, '22' ) ], $post );
		$this->assertSame( '24', $tie->categoryId );

		$none = ( new RuleMatcher() )->match( [ new TermRule( 'category', 'a', 'PLaaaaaaaaaaa', '' ) ], $post );
		$this->assertNull( $none->categoryId );
		$this->assertSame( [ 'PLaaaaaaaaaaa' ], $none->playlists );
	}
}
