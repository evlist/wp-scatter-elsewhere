<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Update;

use Closure;

/**
 * Keeps the bulk update jobs (the last twenty) and the log of their changes, each in an option.
 */
class BatchJobStore {

	public const MAX_JOBS = 20;

	/** Entries kept in the log of a job. */
	public const MAX_LOG = 5000;

	private const JOBS_KEY = 'wp_scatter_elsewhere_batch_jobs';

	/** @var Closure(string): mixed */
	private Closure $loader;

	/** @var Closure(string, mixed): void */
	private Closure $saver;

	/** @var Closure(string): void */
	private Closure $deleter;

	/**
	 * @param Closure(string): mixed       $loader  Reads an option.
	 * @param Closure(string, mixed): void $saver   Writes an option (autoload disabled).
	 * @param Closure(string): void        $deleter Deletes an option.
	 */
	public function __construct( Closure $loader, Closure $saver, Closure $deleter ) {
		$this->loader  = $loader;
		$this->saver   = $saver;
		$this->deleter = $deleter;
	}

	/**
	 * @return BatchJob[] Most recent first.
	 */
	public function all(): array {
		$stored = ( $this->loader )( self::JOBS_KEY );
		$jobs   = [];

		foreach ( is_array( $stored ) ? $stored : [] as $data ) {
			if ( is_array( $data ) ) {
				$job = BatchJob::fromArray( $data );
				if ( '' !== $job->id() ) {
					$jobs[] = $job;
				}
			}
		}

		return $jobs;
	}

	public function get( string $id ): ?BatchJob {
		foreach ( $this->all() as $job ) {
			if ( $job->id() === $id ) {
				return $job;
			}
		}

		return null;
	}

	/**
	 * The job that is running or waiting, if any: there is at most one.
	 */
	public function active(): ?BatchJob {
		foreach ( $this->all() as $job ) {
			if ( $job->isActive() ) {
				return $job;
			}
		}

		return null;
	}

	public function save( BatchJob $job ): void {
		$jobs  = $this->all();
		$found = false;

		foreach ( $jobs as $index => $existing ) {
			if ( $existing->id() === $job->id() ) {
				$jobs[ $index ] = $job;
				$found         = true;
			}
		}

		if ( ! $found ) {
			array_unshift( $jobs, $job );
		}

		foreach ( array_slice( $jobs, self::MAX_JOBS ) as $pruned ) {
			( $this->deleter )( $this->logKey( $pruned->id() ) );
		}

		( $this->saver )( self::JOBS_KEY, array_map( static fn( BatchJob $item ): array => $item->toArray(), array_slice( $jobs, 0, self::MAX_JOBS ) ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $entries
	 */
	public function appendLog( string $id, array $entries ): void {
		if ( [] === $entries ) {
			return;
		}

		( $this->saver )( $this->logKey( $id ), array_slice( array_merge( $this->log( $id ), $entries ), -self::MAX_LOG ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function log( string $id ): array {
		$stored = ( $this->loader )( $this->logKey( $id ) );

		return is_array( $stored ) ? array_values( $stored ) : [];
	}

	private function logKey( string $id ): string {
		return 'wp_scatter_elsewhere_batch_log_' . $id;
	}
}
