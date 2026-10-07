<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Matching\PostFacts;
use WP_Scatter_Elsewhere\Matching\Suggestion;
use WP_Scatter_Elsewhere\Matching\VideoMatcher;
use WP_Scatter_Elsewhere\Matching\VideoSuggester;
use WP_Scatter_Elsewhere\Publication\ChannelCatalog;
use WP_Scatter_Elsewhere\Publication\LinkIndex;
use WP_Scatter_Elsewhere\YouTube\CatalogVideo;

class VideoMatcherTest extends TestCase {

	private const PERMALINK = 'https://e.vli.st/2026/10/05/grenoble-%e2%87%be-salers/';

	private function post( string $title = 'Grenoble ⇾ Salers' ): PostFacts {
		return new PostFacts( $title, self::PERMALINK, strtotime( '2026-10-05T12:00:00Z' ) );
	}

	private function video( string $id, string $title, string $description = '', string $published = '2026-10-06T08:00:00Z', ?string $recorded = null ): CatalogVideo {
		return new CatalogVideo( $id, $title, $published, 'public', $description, $recorded, null );
	}

	public function test_permalink_in_the_description_is_high_confidence_whatever_the_spelling(): void {
		$old = "5 octobre 2026\nUn trajet.\nDétails : http://www.e.vli.st/2026/10/05/grenoble-⇾-salers";
		$result = ( new VideoMatcher() )->match( $this->post(), [ $this->video( 'a', 'Autre titre', $old, '2020-01-01T00:00:00Z' ) ] );

		$this->assertCount( 1, $result );
		$this->assertSame( Suggestion::HIGH, $result[0]->confidence );
		$this->assertSame( [ 'permalink' ], $result[0]->reasons );
	}

	public function test_permalink_of_a_longer_address_does_not_match(): void {
		$result = ( new VideoMatcher() )->match( $this->post(), [ $this->video( 'a', 'x', 'Détails : https://e.vli.st/2026/10/05/grenoble-%E2%87%BE-salers-2/', '2020-01-01T00:00:00Z' ) ] );
		$this->assertSame( [], $result );
	}

	public function test_an_exact_title_is_high_confidence_when_unique(): void {
		$result = ( new VideoMatcher() )->match( $this->post(), [ $this->video( 'a', 'GRENOBLE  ⇾  salers!' ), $this->video( 'b', 'Vélo' ) ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'a', $result[0]->video->id );
		$this->assertSame( Suggestion::HIGH, $result[0]->confidence );
		$this->assertSame( [ 'title' ], $result[0]->reasons );
	}

	public function test_an_exact_title_shared_by_several_videos_is_only_a_suggestion(): void {
		$result = ( new VideoMatcher() )->match( $this->post(), [ $this->video( 'a', 'Grenoble ⇾ Salers', '', '2026-01-01T00:00:00Z' ), $this->video( 'b', 'Grenoble ⇾ Salers', '', '2026-10-05T10:00:00Z' ) ] );

		$this->assertCount( 2, $result );
		$this->assertSame( Suggestion::SUGGESTION, $result[0]->confidence );
		$this->assertSame( 'b', $result[0]->video->id, 'The close date ranks first.' );
	}

	public function test_a_similar_title_needs_a_close_date(): void {
		$matcher = new VideoMatcher();

		$far   = $matcher->match( $this->post(), [ $this->video( 'a', 'Grenoble ⇾ Salers (1)', '', '2026-01-01T00:00:00Z' ) ] );
		$close = $matcher->match( $this->post(), [ $this->video( 'a', 'Grenoble ⇾ Salers (1)', '', '2026-10-07T00:00:00Z' ) ] );

		$this->assertSame( [], $far );
		$this->assertSame( [ 'similar' ], $close[0]->reasons );
		$this->assertSame( Suggestion::SUGGESTION, $close[0]->confidence );
	}

	public function test_the_recording_date_is_compared_before_the_upload_date(): void {
		$matcher = new VideoMatcher();

		$late_upload = $matcher->match( $this->post(), [ $this->video( 'a', 'Grenoble ⇾ Salers (1)', '', '2026-12-01T00:00:00Z', '2026-10-05T00:00:00Z' ) ] );
		$wrong_recording = $matcher->match( $this->post(), [ $this->video( 'a', 'Grenoble ⇾ Salers (1)', '', '2026-10-05T00:00:00Z', '2025-01-01T00:00:00Z' ) ] );

		$this->assertCount( 1, $late_upload );
		$this->assertSame( [], $wrong_recording );
	}

	public function test_ranking_puts_the_permalink_first_and_keeps_three(): void {
		$videos = [
			$this->video( 'a', 'Grenoble ⇾ Salers (a)', '', '2026-10-05T00:00:00Z' ),
			$this->video( 'b', 'Grenoble ⇾ Salers (b)', '', '2026-10-05T00:00:00Z' ),
			$this->video( 'c', 'Grenoble ⇾ Salers (c)', '', '2026-10-05T00:00:00Z' ),
			$this->video( 'd', 'Sans rapport', 'Détails : ' . self::PERMALINK, '2019-01-01T00:00:00Z' ),
		];
		$result = ( new VideoMatcher() )->match( $this->post(), $videos );

		$this->assertCount( 3, $result );
		$this->assertSame( 'd', $result[0]->video->id );
	}

	public function test_nothing_matches_unrelated_videos(): void {
		$this->assertSame( [], ( new VideoMatcher() )->match( $this->post(), [ $this->video( 'a', 'Vélo' ) ] ) );
		$this->assertSame( [], ( new VideoMatcher() )->match( $this->post( '' ), [ $this->video( 'a', '' ) ] ) );
	}

	public function test_the_suggester_never_proposes_a_video_linked_to_another_post(): void {
		$videos  = [ $this->video( 'a', 'Grenoble ⇾ Salers' ), $this->video( 'b', 'Grenoble ⇾ Salers', 'Détails : ' . self::PERMALINK ) ];
		$catalog = new ChannelCatalog(
			static fn( int $limit ): array => $videos,
			static fn(): mixed => false,
			static function ( array $value, int $ttl ): void {},
			static fn(): int => 1,
			new LinkIndex( static fn(): iterable => [ [ 'post_id' => 9, 'video_id' => 'v1', 'youtube_id' => 'b' ] ] )
		);

		$result = ( new VideoSuggester( $catalog, new VideoMatcher() ) )->suggest( $this->post() );

		$this->assertSame( [ 'a' ], array_map( static fn( Suggestion $s ) => $s->video->id, $result ) );
	}
}
