<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Quota\CostTable;
use WP_Scatter_Elsewhere\Quota\QuotaDay;
use WP_Scatter_Elsewhere\Quota\QuotaMeter;
use WP_Scatter_Elsewhere\YouTube\MeteredHttp;

class QuotaTest extends TestCase {

	private mixed $stored = false;

	private int $now;

	protected function setUp(): void {
		// 2026-10-07 12:00 UTC is 05:00 on the same day in Pacific Daylight Time.
		$this->now = strtotime( '2026-10-07T12:00:00Z' );
	}

	private function meter(): QuotaMeter {
		return new QuotaMeter( fn(): mixed => $this->stored, function ( array $value ): void { $this->stored = $value; }, fn(): int => $this->now );
	}

	public function test_identifies_the_requests_and_their_cost(): void {
		$cases = [
			[ 'POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', 'videos.insert', 1600 ],
			[ 'PUT', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=ABC', '', 0 ],
			[ 'PUT', 'https://www.googleapis.com/youtube/v3/videos?part=snippet', 'videos.update', 50 ],
			[ 'GET', 'https://www.googleapis.com/youtube/v3/videos?part=status&id=x', 'videos.list', 1 ],
			[ 'GET', 'https://www.googleapis.com/youtube/v3/playlistItems?part=contentDetails&playlistId=P', 'playlistItems.list', 1 ],
			[ 'POST', 'https://www.googleapis.com/youtube/v3/playlistItems?part=snippet', 'playlistItems.insert', 50 ],
			[ 'DELETE', 'https://www.googleapis.com/youtube/v3/playlistItems?id=x', 'playlistItems.delete', 50 ],
			[ 'GET', 'https://www.googleapis.com/youtube/v3/captions?part=snippet&videoId=x', 'captions.list', 50 ],
			[ 'POST', 'https://www.googleapis.com/upload/youtube/v3/captions?uploadType=multipart&part=snippet', 'captions.insert', 400 ],
			[ 'PUT', 'https://www.googleapis.com/upload/youtube/v3/captions?uploadType=multipart&part=snippet', 'captions.update', 450 ],
			[ 'DELETE', 'https://www.googleapis.com/youtube/v3/captions?id=x', 'captions.delete', 50 ],
			[ 'POST', 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?uploadType=media&videoId=x', 'thumbnails.set', 50 ],
			[ 'GET', 'https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true', 'channels.list', 1 ],
			[ 'POST', 'https://oauth2.googleapis.com/token', '', 0 ],
		];

		foreach ( $cases as [ $verb, $url, $method, $units ] ) {
			$this->assertSame( [ 'method' => $method, 'units' => $units ], CostTable::identify( $verb, $url ), $verb . ' ' . $url );
		}
	}

	public function test_the_quota_day_changes_at_midnight_pacific_time(): void {
		// 06:59 UTC is 23:59 the day before in PDT (UTC-7); 07:00 UTC is midnight.
		$this->assertSame( '2026-10-06', QuotaDay::of( strtotime( '2026-10-07T06:59:59Z' ) ) );
		$this->assertSame( '2026-10-07', QuotaDay::of( strtotime( '2026-10-07T07:00:00Z' ) ) );
		$this->assertSame( strtotime( '2026-10-08T07:00:00Z' ), QuotaDay::nextReset( strtotime( '2026-10-07T12:00:00Z' ) ) );
	}

	public function test_the_quota_day_follows_the_daylight_saving_time(): void {
		// Standard time (UTC-8) in winter: midnight is at 08:00 UTC.
		$this->assertSame( strtotime( '2026-12-16T08:00:00Z' ), QuotaDay::nextReset( strtotime( '2026-12-15T12:00:00Z' ) ) );
		// The day of the change back (2026-11-01) lasts 25 hours.
		$this->assertSame( strtotime( '2026-11-02T08:00:00Z' ), QuotaDay::nextReset( strtotime( '2026-11-01T12:00:00Z' ) ) );
	}

	public function test_counts_per_method_and_per_day(): void {
		$meter = $this->meter();
		$meter->record( 'videos.insert', 1600 );
		$meter->record( 'videos.list', 1 );
		$meter->record( 'videos.list', 1 );
		$meter->record( 'x', 0 );

		$today = $meter->today();
		$this->assertSame( 1602, $today['used'] );
		$this->assertSame( [ 'videos.insert' => 1600, 'videos.list' => 2 ], $today['by'] );
		$this->assertSame( 8398, $meter->remaining() );

		$this->now += 86400;
		$this->assertSame( 0, $meter->today()['used'] );
		$this->assertSame( [ '2026-10-07' => 1602 ], $meter->history() );
	}

	public function test_levels(): void {
		$meter = $this->meter();
		$this->assertSame( QuotaMeter::LEVEL_OK, $meter->level() );

		$meter->record( 'videos.insert', 6400 );
		$this->assertSame( QuotaMeter::LEVEL_OK, $meter->level() );

		$meter->record( 'videos.insert', 1600 );
		$this->assertSame( QuotaMeter::LEVEL_LOW, $meter->level(), '80 % used.' );

		$this->meter()->setLimit( 20000 );
		$this->assertSame( QuotaMeter::LEVEL_OK, $meter->level() );

		$this->meter()->setLimit( 10000 );
		$meter->record( 'x', 1999 );
		$this->assertSame( QuotaMeter::LEVEL_LOW, $meter->level(), 'Less than an upload is left.' );
		$meter->record( 'x', 1 );
		$this->assertSame( QuotaMeter::LEVEL_EXHAUSTED, $meter->level() );
	}

	public function test_an_exhausted_answer_exhausts_the_day_and_the_next_day_starts_clean(): void {
		$meter = $this->meter();
		$meter->record( 'videos.list', 5 );
		$meter->markExhausted();

		$this->assertSame( QuotaMeter::LEVEL_EXHAUSTED, $meter->level() );
		$this->assertSame( 0, $meter->remaining() );
		$this->assertSame( 10000, $meter->today()['used'] );

		$this->now = QuotaDay::nextReset( $this->now ) + 1;
		$this->assertSame( QuotaMeter::LEVEL_OK, $meter->level() );
	}

	public function test_keeps_a_week_and_can_be_reset(): void {
		$meter = $this->meter();
		for ( $i = 0; $i < 10; $i++ ) {
			$meter->record( 'x', 1 );
			$this->now += 86400;
		}
		$this->assertCount( 7, $meter->history() );

		$meter->setLimit( 500 );
		$meter->reset();
		$this->assertSame( [], $meter->history() );
		$this->assertSame( 500, $meter->limit(), 'The limit survives a reset.' );
	}

	public function test_the_metered_http_counts_answers_and_not_transport_errors(): void {
		$meter  = $this->meter();
		$answer = [ 'status' => 200, 'headers' => [], 'body' => '{}' ];
		$http   = MeteredHttp::wrap( static function ( string $m, string $u, array $h, string $b ) use ( &$answer ): array {
			if ( 'FAIL' === $b ) {
				throw new RuntimeException( 'timeout' );
			}
			return $answer;
		}, $meter );

		$http( 'GET', 'https://www.googleapis.com/youtube/v3/videos?id=x', [], '' );
		$this->assertSame( 1, $meter->today()['used'] );

		try {
			$http( 'POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable', [], 'FAIL' );
			$this->fail( 'The error must propagate.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 1, $meter->today()['used'], 'A transport error costs nothing.' );
		}

		$answer = [ 'status' => 403, 'headers' => [], 'body' => '{"error":{"errors":[{"reason":"rateLimitExceeded"}]}}' ];
		$http( 'GET', 'https://www.googleapis.com/youtube/v3/videos?id=x', [], '' );
		$this->assertNotSame( QuotaMeter::LEVEL_EXHAUSTED, $meter->level(), 'A short-term rate limit does not exhaust the day.' );

		$answer = [ 'status' => 403, 'headers' => [], 'body' => '{"error":{"errors":[{"reason":"quotaExceeded"}]}}' ];
		$http( 'POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable', [], '' );
		$this->assertSame( QuotaMeter::LEVEL_EXHAUSTED, $meter->level() );
	}
}
