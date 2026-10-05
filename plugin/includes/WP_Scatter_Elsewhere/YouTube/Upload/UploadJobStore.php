<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube\Upload;

use Closure;

/**
 * Persists the upload jobs in a single option.
 */
class UploadJobStore {

	private const OPTION_KEY = 'wp_scatter_elsewhere_upload_jobs';

	public const MAX_JOBS = 100;

	/**
	 * @var Closure(): mixed
	 */
	private Closure $loader;

	/**
	 * @var Closure(array<string, array<string, mixed>>): void
	 */
	private Closure $saver;

	/**
	 * @param Closure(): mixed                                          $loader
	 * @param Closure(array<string, array<string, mixed>>): void $saver
	 */
	public function __construct( Closure $loader, Closure $saver ) {
		$this->loader = $loader;
		$this->saver  = $saver;
	}

	public static function optionKey(): string {
		return self::OPTION_KEY;
	}

	public function get( string $id ): ?UploadJob {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * @return array<string, UploadJob> Oldest first, keyed by job id.
	 */
	public function all(): array {
		$stored = ( $this->loader )();
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$jobs = [];
		foreach ( $stored as $data ) {
			if ( is_array( $data ) ) {
				$job = UploadJob::fromArray( $data );
				if ( '' !== $job->id() ) {
					$jobs[ $job->id() ] = $job;
				}
			}
		}

		return $jobs;
	}

	public function save( UploadJob $job ): void {
		$jobs                = $this->all();
		$jobs[ $job->id() ] = $job;

		( $this->saver )( array_map( static fn( UploadJob $item ): array => $item->toArray(), $this->prune( $jobs ) ) );
	}

	public function findActive( int $postId, string $videoId ): ?UploadJob {
		foreach ( $this->all() as $job ) {
			if ( $job->isActive() && $job->postId() === $postId && $job->videoId() === $videoId ) {
				return $job;
			}
		}

		return null;
	}

	/**
	 * Drops the oldest finished jobs beyond the limit. Active jobs are never dropped.
	 *
	 * @param array<string, UploadJob> $jobs
	 * @return array<string, UploadJob>
	 */
	private function prune( array $jobs ): array {
		$excess = count( $jobs ) - self::MAX_JOBS;

		foreach ( $jobs as $id => $job ) {
			if ( $excess <= 0 ) {
				break;
			}
			if ( ! $job->isActive() ) {
				unset( $jobs[ $id ] );
				--$excess;
			}
		}

		return $jobs;
	}
}
