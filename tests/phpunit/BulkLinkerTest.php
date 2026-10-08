<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Matching\BulkDecision;
use WP_Scatter_Elsewhere\Matching\BulkEntry;
use WP_Scatter_Elsewhere\Matching\BulkLinker;
use WP_Scatter_Elsewhere\Matching\PostFacts;
use WP_Scatter_Elsewhere\Matching\Suggestion;
use WP_Scatter_Elsewhere\Matching\VideoMatcher;
use WP_Scatter_Elsewhere\YouTube\CatalogVideo;

class BulkLinkerTest extends TestCase {

	private function entry( int $post, string $title, bool $linked = false, string $video = 'v1', string $date = '2026-10-05T12:00:00Z' ): BulkEntry {
		return new BulkEntry( $post, $video, new PostFacts( $title, 'https://e.vli.st/p' . $post . '/', strtotime( $date ) ), $linked );
	}

	private function video( string $id, string $title, string $description = '', string $published = '2026-10-06T00:00:00Z' ): CatalogVideo {
		return new CatalogVideo( $id, $title, $published, 'public', $description, null, null );
	}

	/** @return string[] */
	private function decisions( array $entries, array $videos, string $min = Suggestion::HIGH ): array {
		return array_map( static fn( BulkDecision $d ): string => $d->decision, ( new BulkLinker( new VideoMatcher() ) )->plan( $entries, $videos, $min ) );
	}

	public function test_decides_each_case(): void {
		$entries = [
			$this->entry( 1, 'Grenoble ⇾ Salers' ),
			$this->entry( 2, 'Déjà fait', true ),
			$this->entry( 3, 'Rien de tel' ),
			$this->entry( 4, 'Vélo à Lyon (suite)' ),
		];
		$videos = [ $this->video( 'a', 'Grenoble ⇾ Salers' ), $this->video( 'b', 'Vélo à Lyon (suite 2)' ) ];

		$this->assertSame(
			[ BulkDecision::WOULD_LINK, BulkDecision::ALREADY_LINKED, BulkDecision::NO_CANDIDATE, BulkDecision::NEEDS_DECISION ],
			$this->decisions( $entries, $videos )
		);
	}

	public function test_a_lower_minimum_links_the_suggestions(): void {
		$this->assertSame(
			[ BulkDecision::WOULD_LINK ],
			$this->decisions( [ $this->entry( 4, 'Vélo à Lyon (suite)' ) ], [ $this->video( 'b', 'Vélo à Lyon (suite 2)' ) ], Suggestion::SUGGESTION )
		);
	}

	public function test_a_video_claimed_by_two_posts_is_left_to_a_decision(): void {
		$videos = [ $this->video( 'a', 'Grenoble ⇾ Salers' ) ];
		$plan   = ( new BulkLinker( new VideoMatcher() ) )->plan( [ $this->entry( 1, 'Grenoble ⇾ Salers' ), $this->entry( 2, 'Grenoble ⇾ Salers', false, 'v2' ) ], $videos );

		$this->assertSame( BulkDecision::NEEDS_DECISION, $plan[0]->decision );
		$this->assertSame( BulkDecision::NEEDS_DECISION, $plan[1]->decision );
		$this->assertSame( 'a', $plan[0]->suggestion->video->id );
	}

	public function test_linked_entries_do_not_claim_videos(): void {
		$videos = [ $this->video( 'a', 'Grenoble ⇾ Salers' ) ];

		$this->assertSame(
			[ BulkDecision::ALREADY_LINKED, BulkDecision::WOULD_LINK ],
			$this->decisions( [ $this->entry( 1, 'Grenoble ⇾ Salers', true ), $this->entry( 2, 'Grenoble ⇾ Salers' ) ], $videos )
		);
	}

	public function test_the_permalink_of_each_post_pairs_videos_with_the_same_title(): void {
		$videos = [
			$this->video( 'a', 'Même titre', 'Détails : https://e.vli.st/p1/' ),
			$this->video( 'b', 'Même titre', 'Détails : https://e.vli.st/p2/' ),
		];
		$plan = ( new BulkLinker( new VideoMatcher() ) )->plan( [ $this->entry( 1, 'Même titre' ), $this->entry( 2, 'Même titre', false, 'v2' ) ], $videos );

		$this->assertSame( [ BulkDecision::WOULD_LINK, BulkDecision::WOULD_LINK ], array_map( static fn( $d ) => $d->decision, $plan ) );
		$this->assertSame( [ 'a', 'b' ], array_map( static fn( $d ) => $d->suggestion->video->id, $plan ) );
	}
}
