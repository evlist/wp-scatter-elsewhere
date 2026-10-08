<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Update;

use Closure;
use InvalidArgumentException;

/**
 * Runs the bulk update jobs in short steps, as the uploads are: a time budget per step, a lock, a pause when the
 * quota of the day is used up, a stop that is honoured between two videos.
 */
final class BatchRunner {

	/** Seconds before a step that stopped without unlocking can be taken over. */
	private const LOCK_GRACE = 60;

	/** Quota units a video may need at worst (a read, an update and its own read). */
	private const MIN_UNITS = 52;

	private BatchJobStore $store;

	/** @var Closure(BatchPlan, array{post: int, video: string, youtube: string}): VideoReport */
	private Closure $process;

	/** @var Closure(): int */
	private Closure $clock;

	/** @var Closure(int, string): void */
	private Closure $schedule;

	/** @var Closure(): array{exhausted: bool, remaining: int, reset: int} */
	private Closure $quota;

	/** @var Closure(): string */
	private Closure $ids;

	/**
	 * @param Closure(BatchPlan, array{post: int, video: string, youtube: string}): VideoReport $process Applies the plan to a video, reading its current state.
	 * @param Closure(): int                                                                  $clock
	 * @param Closure(int, string): void                                                      $schedule Runs a step of a job at a time.
	 * @param Closure(): array{exhausted: bool, remaining: int, reset: int}                   $quota    State of the quota of the day and Unix time of its reset.
	 * @param Closure(): string                                                               $ids
	 */
	public function __construct( BatchJobStore $store, Closure $process, Closure $clock, Closure $schedule, Closure $quota, Closure $ids ) {
		$this->store    = $store;
		$this->process  = $process;
		$this->clock    = $clock;
		$this->schedule = $schedule;
		$this->quota    = $quota;
		$this->ids      = $ids;
	}

	/**
	 * @param array<int, array{post: int, video: string, youtube: string}> $targets
	 * @throws InvalidArgumentException When a job is already running or nothing is to be done.
	 */
	public function start( BatchPlan $plan, array $targets ): BatchJob {
		if ( null !== $this->store->active() ) {
			throw new InvalidArgumentException( __( 'Another update is still running: wait for it to finish or stop it.', 'wp-scatter-elsewhere' ) );
		}

		if ( [] === $targets ) {
			throw new InvalidArgumentException( __( 'No linked video matches this selection.', 'wp-scatter-elsewhere' ) );
		}

		$now = ( $this->clock )();
		$job = BatchJob::fromArray( [ 'id' => ( $this->ids )(), 'plan' => $plan->toArray(), 'targets' => $targets, 'created_at' => $now ] );

		$this->store->save( $job );
		( $this->schedule )( $now, $job->id() );

		return $job;
	}

	public function stop( string $id ): ?BatchJob {
		$job = $this->store->get( $id );
		if ( null === $job || ! $job->isActive() ) {
			return $job;
		}

		// A job that is not running right now stops at once; a running step stops between two videos.
		$locked = $job->lockedUntil() > ( $this->clock )();
		$job    = $job->with( $locked ? [ 'stop' => 1 ] : [ 'status' => BatchJob::STATUS_STOPPED, 'stop' => 0, 'message' => __( 'Stopped.', 'wp-scatter-elsewhere' ) ] );
		$this->store->save( $job );

		return $job;
	}

	public function resume( string $id ): ?BatchJob {
		$job = $this->store->get( $id );
		if ( null === $job || BatchJob::STATUS_QUEUED === $job->status() || BatchJob::STATUS_DONE === $job->status() ) {
			return $job;
		}

		if ( BatchJob::STATUS_STOPPED === $job->status() && null !== $this->store->active() ) {
			throw new InvalidArgumentException( __( 'Another update is still running: wait for it to finish or stop it.', 'wp-scatter-elsewhere' ) );
		}

		$job = $job->with( [ 'status' => BatchJob::STATUS_QUEUED, 'stop' => 0, 'pause_until' => 0, 'message' => '' ] );
		$this->store->save( $job );
		( $this->schedule )( ( $this->clock )(), $job->id() );

		return $job;
	}

	/**
	 * One step: videos are processed one after the other until the budget is spent.
	 */
	public function step( string $id, int $budgetSeconds ): ?BatchJob {
		$job = $this->store->get( $id );
		$now = ( $this->clock )();

		if ( null === $job || ! $job->isActive() || $job->lockedUntil() > $now ) {
			return $job;
		}

		if ( BatchJob::STATUS_PAUSED === $job->status() && $job->pauseUntil() > $now ) {
			( $this->schedule )( $job->pauseUntil(), $id );

			return $job;
		}

		$deadline = $now + $budgetSeconds;
		$job      = $job->with( [ 'status' => BatchJob::STATUS_QUEUED, 'pause_until' => 0, 'locked_until' => $deadline + self::LOCK_GRACE ] );
		$this->store->save( $job );
		$plan = $job->plan();

		while ( $job->cursor() < $job->total() && ( $this->clock )() < $deadline ) {
			// The page may have asked to stop since the last video.
			$fresh = $this->store->get( $id );
			if ( null !== $fresh && $fresh->stopRequested() ) {
				return $this->finish( $job, [ 'status' => BatchJob::STATUS_STOPPED, 'stop' => 0, 'message' => __( 'Stopped.', 'wp-scatter-elsewhere' ) ] );
			}

			$quota = ( $this->quota )();
			if ( $quota['exhausted'] || $quota['remaining'] < self::MIN_UNITS ) {
				return $this->pause( $job, $quota['reset'] );
			}

			$target = $job->targets()[ $job->cursor() ];
			$report = ( $this->process )( $plan, $target );
			$job    = $this->record( $job, $target, $report );

			if ( $report->stopped ) {
				return $this->pause( $job, ( ( $this->quota )() )['reset'] );
			}
		}

		if ( $job->cursor() >= $job->total() ) {
			return $this->finish( $job, [ 'status' => BatchJob::STATUS_DONE, 'message' => __( 'Done.', 'wp-scatter-elsewhere' ) ] );
		}

		$job = $job->with( [ 'locked_until' => 0 ] );
		$this->store->save( $job );
		( $this->schedule )( ( $this->clock )() + 1, $id );

		return $job;
	}

	/**
	 * @param array{post: int, video: string, youtube: string} $target
	 */
	private function record( BatchJob $job, array $target, VideoReport $report ): BatchJob {
		$now     = ( $this->clock )();
		$entries = [];

		foreach ( $report->rows as $row ) {
			$entries[] = array_merge( [ 'time' => $now, 'post' => $target['post'], 'video' => $target['video'], 'youtube' => $target['youtube'] ], $row );
		}
		$this->store->appendLog( $job->id(), $entries );

		// A stop asked by the page while this video was processed must not be overwritten by this save.
		$asked = $this->store->get( $job->id() );
		$job   = $job->with( [ 'stop' => null !== $asked && $asked->stopRequested() ? 1 : 0 ] );

		$differences = array_filter( $report->rows, static fn( array $row ): bool => 'error' !== $row['action'] );
		$job         = $job->with(
			[
				'cursor'    => $job->cursor() + 1,
				'changed'   => $job->changed() + ( [] !== $differences ? 1 : 0 ),
				'unchanged' => $job->unchanged() + ( [] === $report->rows ? 1 : 0 ),
				'errors'    => $job->errors() + $report->errors,
				'cost'      => $job->cost() + $report->cost,
				'updated_at' => $now,
			]
		);
		$this->store->save( $job );

		return $job;
	}

	private function pause( BatchJob $job, int $reset ): BatchJob {
		$until = $reset + 60;
		$job   = $job->with(
			[
				'status'       => BatchJob::STATUS_PAUSED,
				'pause_until'  => $until,
				'locked_until' => 0,
				'message'      => __( 'The daily YouTube quota is used up: the update resumes after the reset of the quota.', 'wp-scatter-elsewhere' ),
			]
		);
		$this->store->save( $job );
		( $this->schedule )( $until, $job->id() );

		return $job;
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	private function finish( BatchJob $job, array $changes ): BatchJob {
		$job = $job->with( array_merge( [ 'locked_until' => 0, 'updated_at' => ( $this->clock )() ], $changes ) );
		$this->store->save( $job );

		return $job;
	}
}
