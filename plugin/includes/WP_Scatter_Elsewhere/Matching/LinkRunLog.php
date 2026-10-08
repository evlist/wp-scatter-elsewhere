<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Matching;

use Closure;

/**
 * The last runs of the bulk linking, kept to undo them. It is only an aid: nothing else depends on it.
 */
final class LinkRunLog {

	private const KEPT = 20;

	/** @var Closure(): mixed */
	private Closure $loader;

	/** @var Closure(array<int, array<string, mixed>>): void */
	private Closure $saver;

	/** @var Closure(): int */
	private Closure $clock;

	/** @var Closure(): string */
	private Closure $ids;

	/**
	 * @param Closure(): mixed                                      $loader
	 * @param Closure(array<int, array<string, mixed>>): void       $saver
	 * @param Closure(): int                                        $clock
	 * @param Closure(): string                                     $ids    Generates the id of a run.
	 */
	public function __construct( Closure $loader, Closure $saver, Closure $clock, Closure $ids ) {
		$this->loader = $loader;
		$this->saver  = $saver;
		$this->clock  = $clock;
		$this->ids    = $ids;
	}

	/**
	 * Adds links to the run of the page (a run spans several requests), or starts it.
	 *
	 * @param array<int, array{post: int, video: string, youtube: string}> $items
	 */
	public function add( string $runId, array $items ): string {
		if ( [] === $items ) {
			return $runId;
		}

		$runId = '' === $runId ? ( $this->ids )() : $runId;
		$runs  = $this->runs();

		foreach ( $runs as $index => $run ) {
			if ( $run['id'] === $runId ) {
				$runs[ $index ]['items'] = array_merge( $run['items'], $items );
				( $this->saver )( $runs );

				return $runId;
			}
		}

		array_unshift( $runs, [ 'id' => $runId, 'time' => ( $this->clock )(), 'items' => $items ] );
		( $this->saver )( array_slice( $runs, 0, self::KEPT ) );

		return $runId;
	}

	/**
	 * @return array<int, array{id: string, time: int, items: array<int, array{post: int, video: string, youtube: string}>}> Most recent first.
	 */
	public function runs(): array {
		$stored = ( $this->loader )();
		$runs   = [];

		foreach ( is_array( $stored ) ? $stored : [] as $run ) {
			if ( ! is_array( $run ) || ! isset( $run['id'] ) ) {
				continue;
			}

			$items = [];
			foreach ( (array) ( $run['items'] ?? [] ) as $item ) {
				if ( is_array( $item ) && isset( $item['post'], $item['video'], $item['youtube'] ) ) {
					$items[] = [ 'post' => (int) $item['post'], 'video' => (string) $item['video'], 'youtube' => (string) $item['youtube'] ];
				}
			}

			$runs[] = [ 'id' => (string) $run['id'], 'time' => (int) ( $run['time'] ?? 0 ), 'items' => $items ];
		}

		return $runs;
	}

	/**
	 * Forgets links that were undone; a run with nothing left disappears.
	 *
	 * @param array<int, array{post: int, video: string}> $undone
	 */
	public function forget( string $runId, array $undone ): void {
		$runs = [];

		foreach ( $this->runs() as $run ) {
			if ( $run['id'] === $runId ) {
				$run['items'] = array_values(
					array_filter(
						$run['items'],
						static function ( array $item ) use ( $undone ): bool {
							foreach ( $undone as $gone ) {
								if ( $gone['post'] === $item['post'] && $gone['video'] === $item['video'] ) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}

			if ( [] !== $run['items'] ) {
				$runs[] = $run;
			}
		}

		( $this->saver )( $runs );
	}
}
