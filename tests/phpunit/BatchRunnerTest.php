<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Update\BatchJob;
use WP_Scatter_Elsewhere\Update\BatchJobStore;
use WP_Scatter_Elsewhere\Update\BatchPlan;
use WP_Scatter_Elsewhere\Update\BatchRunner;
use WP_Scatter_Elsewhere\Update\VideoReport;

class BatchRunnerTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	private int $now = 1000;

	/** @var array<int, array{int, string}> */
	private array $scheduled = [];

	/** @var string[] */
	private array $processed = [];

	/** @var array<string, VideoReport> Reports by YouTube id; a default one is used otherwise. */
	private array $reports = [];

	private array $quota = [ 'exhausted' => false, 'remaining' => 10000, 'reset' => 5000 ];

	private int $secondsPerVideo = 1;

	private ?Closure $afterVideo = null;

	private function store(): BatchJobStore {
		return new BatchJobStore(
			fn( string $key ): mixed => $this->options[ $key ] ?? false,
			function ( string $key, mixed $value ): void { $this->options[ $key ] = $value; },
			function ( string $key ): void { unset( $this->options[ $key ] ); }
		);
	}

	private function runner(): BatchRunner {
		return new BatchRunner(
			$this->store(),
			function ( BatchPlan $plan, array $target ): VideoReport {
				$this->processed[] = $target['youtube'];
				$this->now        += $this->secondsPerVideo;
				if ( null !== $this->afterVideo ) {
					( $this->afterVideo )( $target );
				}

				return $this->reports[ $target['youtube'] ] ?? new VideoReport( [ [ 'field' => 'license', 'current' => 'youtube', 'new' => 'creativeCommon', 'action' => 'updated' ] ], 52, 0, false );
			},
			fn(): int => $this->now,
			function ( int $when, string $id ): void { $this->scheduled[] = [ $when, $id ]; },
			fn(): array => $this->quota,
			fn(): string => 'job1'
		);
	}

	private function plan(): BatchPlan {
		return BatchPlan::fromArray( [ 'fields' => 'license' ] );
	}

	private function targets( int $n ): array {
		$targets = [];
		for ( $i = 1; $i <= $n; $i++ ) {
			$targets[] = [ 'post' => $i, 'video' => 'v' . $i, 'youtube' => 'y' . $i ];
		}

		return $targets;
	}

	public function test_plans_are_validated(): void {
		$plan = BatchPlan::fromArray( [ 'fields' => 'all-safe', 'playlists' => 'sync', 'posts' => '5, 7', 'since' => '2026-01-01', 'term' => 'category:vanlife', 'limit' => '10' ] );
		$this->assertNotContains( 'title', $plan->fields );
		$this->assertContains( 'playlists', $plan->fields );
		$this->assertSame( 'sync', $plan->playlists );
		$this->assertSame( [ 5, 7 ], $plan->posts );
		$this->assertSame( 10, $plan->limit );
		$this->assertEquals( $plan, BatchPlan::fromArray( $plan->toArray() ) );

		foreach ( [ [ 'fields' => '' ], [ 'fields' => 'nonsense' ], [ 'fields' => 'license', 'keywords' => 'all' ], [ 'fields' => 'privacy' ], [ 'fields' => 'privacy', 'privacy' => 'secret' ], [ 'fields' => 'license', 'since' => '01/02/2026' ], [ 'fields' => 'license', 'term' => 'vanlife' ] ] as $bad ) {
			try {
				BatchPlan::fromArray( $bad );
				$this->fail( 'Expected a refusal: ' . json_encode( $bad ) );
			} catch ( InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}

		$this->assertSame( 'public', BatchPlan::fromArray( [ 'fields' => 'privacy', 'privacy' => 'public' ] )->privacy );
		$this->assertNull( BatchPlan::fromArray( [ 'fields' => 'license', 'privacy' => 'public' ] )->privacy, 'The privacy is only kept when the field is asked for.' );
	}

	public function test_a_job_goes_through_its_videos_and_counts_them(): void {
		$this->reports['y2'] = new VideoReport( [], 1, 0, false );
		$this->reports['y3'] = new VideoReport( [ [ 'field' => 'license', 'current' => '', 'new' => '', 'action' => 'error' ] ], 1, 1, false );
		$runner              = $this->runner();

		$job = $runner->start( $this->plan(), $this->targets( 3 ) );
		$this->assertSame( [ [ 1000, 'job1' ] ], $this->scheduled );

		$job = $runner->step( $job->id(), 20 );

		$this->assertSame( BatchJob::STATUS_DONE, $job->status() );
		$this->assertSame( [ 'y1', 'y2', 'y3' ], $this->processed );
		$this->assertSame( [ 1, 1, 1 ], [ $job->changed(), $job->unchanged(), $job->errors() ] );
		$this->assertSame( 54, $job->cost() );
		$this->assertSame( 0, $job->lockedUntil() );
		$this->assertCount( 2, $this->store()->log( 'job1' ), 'Videos that match leave no log entry.' );
		$this->assertSame( 'y1', $this->store()->log( 'job1' )[0]['youtube'] );
		$this->assertSame( 'youtube', $this->store()->log( 'job1' )[0]['current'], 'The previous value is logged.' );
	}

	public function test_a_step_stops_at_the_budget_and_reschedules(): void {
		$runner = $this->runner();
		$job    = $runner->start( $this->plan(), $this->targets( 10 ) );

		$job = $runner->step( $job->id(), 3 );

		$this->assertSame( 3, $job->cursor() );
		$this->assertTrue( $job->isActive() );
		$this->assertSame( 0, $job->lockedUntil() );
		$this->assertCount( 2, $this->scheduled );

		$job = $runner->step( $job->id(), 100 );
		$this->assertSame( BatchJob::STATUS_DONE, $job->status() );
		$this->assertSame( 10, count( $this->processed ) );
	}

	public function test_a_locked_job_is_not_run_twice(): void {
		$runner = $this->runner();
		$job    = $runner->start( $this->plan(), $this->targets( 2 ) );
		$store  = $this->store();
		$store->save( $job->with( [ 'locked_until' => $this->now + 50 ] ) );

		$runner->step( $job->id(), 20 );

		$this->assertSame( [], $this->processed );
	}

	public function test_only_one_job_runs_at_a_time(): void {
		$runner = $this->runner();
		$runner->start( $this->plan(), $this->targets( 2 ) );

		$this->expectException( InvalidArgumentException::class );
		$runner->start( $this->plan(), $this->targets( 2 ) );
	}

	public function test_nothing_to_do_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->runner()->start( $this->plan(), [] );
	}

	public function test_an_exhausted_quota_pauses_the_job_until_after_the_reset_and_it_resumes(): void {
		$this->quota = [ 'exhausted' => true, 'remaining' => 0, 'reset' => 5000 ];
		$runner      = $this->runner();
		$job         = $runner->start( $this->plan(), $this->targets( 2 ) );

		$job = $runner->step( $job->id(), 20 );

		$this->assertSame( BatchJob::STATUS_PAUSED, $job->status() );
		$this->assertSame( 5060, $job->pauseUntil() );
		$this->assertSame( [], $this->processed );
		$this->assertSame( [ 5060, 'job1' ], end( $this->scheduled ) );
		$this->assertTrue( $job->isActive() );

		// Too early: the step only reschedules.
		$runner->step( $job->id(), 20 );
		$this->assertSame( [], $this->processed );

		$this->now   = 5100;
		$this->quota = [ 'exhausted' => false, 'remaining' => 10000, 'reset' => 90000 ];
		$job         = $runner->step( $job->id(), 20 );
		$this->assertSame( BatchJob::STATUS_DONE, $job->status() );
	}

	public function test_a_quota_refusal_during_a_video_pauses_after_it(): void {
		$this->reports['y1'] = new VideoReport( [], 3, 1, true );
		$runner              = $this->runner();
		$job                 = $runner->start( $this->plan(), $this->targets( 3 ) );

		$job = $runner->step( $job->id(), 20 );

		$this->assertSame( BatchJob::STATUS_PAUSED, $job->status() );
		$this->assertSame( 1, $job->cursor() );
		$this->assertSame( [ 'y1' ], $this->processed );
	}

	public function test_too_little_quota_for_a_video_pauses_too(): void {
		$this->quota = [ 'exhausted' => false, 'remaining' => 10, 'reset' => 5000 ];
		$runner      = $this->runner();
		$job         = $runner->start( $this->plan(), $this->targets( 2 ) );

		$this->assertSame( BatchJob::STATUS_PAUSED, $runner->step( $job->id(), 20 )->status() );
	}

	public function test_a_stop_asked_during_a_step_is_honoured_between_two_videos_and_the_job_resumes(): void {
		$runner = $this->runner();
		$job    = $runner->start( $this->plan(), $this->targets( 5 ) );

		$this->afterVideo = function ( array $target ) use ( $runner ): void {
			if ( 'y2' === $target['youtube'] ) {
				$runner->stop( 'job1' );
			}
		};
		$job = $runner->step( $job->id(), 100 );

		$this->assertSame( BatchJob::STATUS_STOPPED, $job->status() );
		$this->assertSame( 2, $job->cursor() );
		$this->assertFalse( $job->isActive() );

		$this->afterVideo = null;
		$runner->resume( 'job1' );
		$job = $runner->step( 'job1', 100 );
		$this->assertSame( BatchJob::STATUS_DONE, $job->status() );
		$this->assertSame( [ 'y1', 'y2', 'y3', 'y4', 'y5' ], $this->processed );
	}

	public function test_a_job_that_is_not_running_stops_at_once(): void {
		$runner = $this->runner();
		$job    = $runner->start( $this->plan(), $this->targets( 2 ) );

		$this->assertSame( BatchJob::STATUS_STOPPED, $runner->stop( $job->id() )->status() );
		$this->assertNull( $this->store()->active() );

		$after = $runner->step( $job->id(), 20 );
		$this->assertSame( [], $this->processed );
		$this->assertSame( BatchJob::STATUS_STOPPED, $after->status() );
	}

	public function test_only_twenty_jobs_are_kept_with_their_logs(): void {
		$store = $this->store();
		for ( $i = 1; $i <= 22; $i++ ) {
			$store->save( BatchJob::fromArray( [ 'id' => 'j' . $i, 'status' => 'done' ] ) );
			$store->appendLog( 'j' . $i, [ [ 'field' => 'x' ] ] );
		}

		$this->assertCount( 20, $store->all() );
		$this->assertSame( 'j22', $store->all()[0]->id() );
		$this->assertArrayNotHasKey( 'wp_scatter_elsewhere_batch_log_j1', $this->options );
		$this->assertArrayHasKey( 'wp_scatter_elsewhere_batch_log_j22', $this->options );
	}
}
