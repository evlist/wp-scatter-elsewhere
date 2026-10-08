<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Matching\BulkApplier;
use WP_Scatter_Elsewhere\Matching\BulkDecision;
use WP_Scatter_Elsewhere\Matching\BulkLinker;
use WP_Scatter_Elsewhere\Matching\BulkScan;
use WP_Scatter_Elsewhere\Matching\LinkRunLog;
use WP_Scatter_Elsewhere\Matching\PostFacts;
use WP_Scatter_Elsewhere\Matching\Suggestion;
use WP_Scatter_Elsewhere\Matching\VideoMatcher;
use WP_Scatter_Elsewhere\Publication\LinkException;
use WP_Scatter_Elsewhere\YouTube\CatalogVideo;

class LinkToolsTest extends TestCase {

	private function scan(): BulkScan {
		$matcher = new VideoMatcher();

		return new BulkScan( new BulkLinker( $matcher ), $matcher );
	}

	private function video( string $id, string $title ): CatalogVideo {
		return new CatalogVideo( $id, $title, '2026-10-06T00:00:00Z', 'public', '', null, null );
	}

	private function examine(): Closure {
		return static function ( int $postId ): array {
			if ( 99 === $postId ) {
				throw new RuntimeException( 'page unreadable' );
			}

			return [
				'facts'  => new PostFacts( 'Titre ' . $postId, 'https://e.vli.st/p' . $postId . '/', strtotime( 5 === $postId ? '2019-01-01T12:00:00Z' : '2026-10-05T12:00:00Z' ) ),
				'videos' => [ 'v' . $postId ],
				'linked' => 3 === $postId ? [ 'v3' => true ] : [],
			];
		};
	}

	public function test_the_scan_goes_on_in_chunks_with_a_cursor(): void {
		$videos = [ $this->video( 'a', 'Titre 1' ), $this->video( 'b', 'Titre 2' ) ];
		$ids    = [ 1, 2, 3, 99, 5 ];

		$first = $this->scan()->chunk( $ids, 0, 2, $this->examine(), $videos, Suggestion::HIGH );
		$this->assertSame( 2, $first['next'] );
		$this->assertSame( 5, $first['total'] );
		$this->assertSame( [ BulkDecision::WOULD_LINK, BulkDecision::WOULD_LINK ], array_column( $first['rows'], 'decision' ) );
		$this->assertSame( 'a', $first['rows'][0]['best']->video->id );
		$this->assertSame( 'a', $first['rows'][0]['candidates'][0]->video->id );

		$second = $this->scan()->chunk( $ids, 2, 2, $this->examine(), $videos, Suggestion::HIGH );
		$this->assertSame( 4, $second['next'] );
		$this->assertSame( [ BulkDecision::ALREADY_LINKED ], array_column( $second['rows'], 'decision' ) );
		$this->assertSame( [], $second['rows'][0]['candidates'] );
		$this->assertSame( [ [ 'post' => 99, 'message' => 'page unreadable' ] ], $second['errors'] );

		$last = $this->scan()->chunk( $ids, 4, 2, $this->examine(), $videos, Suggestion::HIGH );
		$this->assertNull( $last['next'] );
		$this->assertSame( [ BulkDecision::NO_CANDIDATE ], array_column( $last['rows'], 'decision' ) );
	}

	public function test_the_applier_refuses_a_video_ticked_twice_and_reports_each_refusal(): void {
		$linked  = [];
		$applier = new BulkApplier(
			static function ( int $post, string $video, string $youtube ) use ( &$linked ): void {
				if ( 'bad' === $youtube ) {
					throw new LinkException( 'other_channel', 'Another channel.' );
				}
				$linked[] = $post . ':' . $video . ':' . $youtube;
			}
		);

		$outcomes = $applier->apply(
			[
				[ 'post' => 1, 'video' => 'v1', 'youtube' => 'a' ],
				[ 'post' => 2, 'video' => 'v2', 'youtube' => 'dup' ],
				[ 'post' => 3, 'video' => 'v3', 'youtube' => 'dup' ],
				[ 'post' => 4, 'video' => 'v4', 'youtube' => 'bad' ],
			]
		);

		$this->assertSame( [ '1:v1:a' ], $linked );
		$this->assertSame( [ true, false, false, false ], array_column( $outcomes, 'linked' ) );
		$this->assertSame( 'Another channel.', $outcomes[3]['message'] );
		$this->assertNotSame( '', $outcomes[1]['message'] );
	}

	public function test_the_run_log_groups_the_links_of_a_run_keeps_twenty_runs_and_forgets_undone_links(): void {
		$stored = false;
		$n      = 0;
		$log    = new LinkRunLog( static function () use ( &$stored ) { return $stored; }, static function ( array $v ) use ( &$stored ): void { $stored = $v; }, static fn(): int => 1000, static function () use ( &$n ): string { return 'run' . ++$n; } );

		$id = $log->add( '', [ [ 'post' => 1, 'video' => 'v1', 'youtube' => 'a' ] ] );
		$this->assertSame( 'run1', $id );
		$this->assertSame( $id, $log->add( $id, [ [ 'post' => 2, 'video' => 'v2', 'youtube' => 'b' ] ] ) );
		$this->assertCount( 1, $log->runs() );
		$this->assertCount( 2, $log->runs()[0]['items'] );

		$this->assertSame( '', $log->add( '', [] ), 'Nothing to log, no run.' );

		$log->forget( $id, [ [ 'post' => 1, 'video' => 'v1' ] ] );
		$this->assertSame( [ [ 'post' => 2, 'video' => 'v2', 'youtube' => 'b' ] ], $log->runs()[0]['items'] );

		$log->forget( $id, [ [ 'post' => 2, 'video' => 'v2' ] ] );
		$this->assertSame( [], $log->runs() );

		for ( $i = 0; $i < 25; $i++ ) {
			$log->add( '', [ [ 'post' => $i, 'video' => 'v', 'youtube' => 'y' . $i ] ] );
		}
		$this->assertCount( 20, $log->runs() );
	}
}
