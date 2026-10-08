<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Quota;

use Closure;

/**
 * Counts the quota units spent by the plugin, per quota day. It is an estimate: the Data API cannot say
 * how much quota was used, and other tools of the same Google Cloud project are not counted.
 */
final class QuotaMeter {

	public const DEFAULT_LIMIT = 10000;

	/** Units of an upload, used to tell whether another one fits. */
	public const UPLOAD_COST = 1600;

	/** Share of the limit from which the level is "low". */
	private const LOW_RATIO = 0.8;

	/** Number of days kept. */
	private const KEPT_DAYS = 7;

	public const LEVEL_OK        = 'ok';
	public const LEVEL_LOW       = 'low';
	public const LEVEL_EXHAUSTED = 'exhausted';

	/**
	 * @var Closure(): mixed
	 */
	private Closure $loader;

	/**
	 * @var Closure(array<string, mixed>): void
	 */
	private Closure $saver;

	/**
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * @param Closure(): mixed                    $loader
	 * @param Closure(array<string, mixed>): void $saver
	 * @param Closure(): int                      $clock  Current Unix time.
	 */
	public function __construct( Closure $loader, Closure $saver, Closure $clock ) {
		$this->loader = $loader;
		$this->saver  = $saver;
		$this->clock  = $clock;
	}

	public function record( string $method, int $units ): void {
		if ( $units <= 0 ) {
			return;
		}

		$data = $this->data();
		$day  = QuotaDay::of( ( $this->clock )() );
		$entry = $data['days'][ $day ] ?? [ 'used' => 0, 'by' => [], 'exhausted' => false ];

		$entry['used']         += $units;
		$entry['by'][ $method ] = ( $entry['by'][ $method ] ?? 0 ) + $units;

		$data['days'][ $day ] = $entry;
		$this->save( $data );
	}

	/**
	 * YouTube said the quota is exhausted: the day counts as used up whatever the estimate said.
	 */
	public function markExhausted(): void {
		$data = $this->data();
		$day  = QuotaDay::of( ( $this->clock )() );
		$entry = $data['days'][ $day ] ?? [ 'used' => 0, 'by' => [], 'exhausted' => false ];

		$entry['exhausted'] = true;
		$entry['used']      = max( $entry['used'], $data['limit'] );

		$data['days'][ $day ] = $entry;
		$this->save( $data );
	}

	public function limit(): int {
		return $this->data()['limit'];
	}

	public function setLimit( int $limit ): void {
		$data          = $this->data();
		$data['limit'] = max( 1, $limit );
		$this->save( $data );
	}

	/**
	 * @return array{date: string, used: int, by: array<string, int>, exhausted: bool}
	 */
	public function today(): array {
		$day   = QuotaDay::of( ( $this->clock )() );
		$entry = $this->data()['days'][ $day ] ?? [ 'used' => 0, 'by' => [], 'exhausted' => false ];

		return [ 'date' => $day, 'used' => $entry['used'], 'by' => $entry['by'], 'exhausted' => $entry['exhausted'] ];
	}

	/**
	 * @return array<string, int> Units used by date, most recent first.
	 */
	public function history(): array {
		$days = array_map( static fn( array $entry ): int => $entry['used'], $this->data()['days'] );
		krsort( $days );

		return $days;
	}

	public function remaining(): int {
		$today = $this->today();

		return $today['exhausted'] ? 0 : max( 0, $this->limit() - $today['used'] );
	}

	/**
	 * "exhausted" when YouTube said so or nothing is left, "low" when 80 % are used or fewer units are left
	 * than an upload costs, "ok" otherwise.
	 */
	public function level(): string {
		$remaining = $this->remaining();

		if ( 0 === $remaining ) {
			return self::LEVEL_EXHAUSTED;
		}

		$used = $this->limit() - $remaining;

		return $used >= $this->limit() * self::LOW_RATIO || $remaining < self::UPLOAD_COST ? self::LEVEL_LOW : self::LEVEL_OK;
	}

	public function nextReset(): int {
		return QuotaDay::nextReset( ( $this->clock )() );
	}

	public function reset(): void {
		$data         = $this->data();
		$data['days'] = [];
		$this->save( $data );
	}

	/**
	 * @return array{limit: int, days: array<string, array{used: int, by: array<string, int>, exhausted: bool}>}
	 */
	private function data(): array {
		$stored = ( $this->loader )();
		$stored = is_array( $stored ) ? $stored : [];
		$limit  = isset( $stored['limit'] ) && (int) $stored['limit'] > 0 ? (int) $stored['limit'] : self::DEFAULT_LIMIT;
		$days   = [];

		foreach ( is_array( $stored['days'] ?? null ) ? $stored['days'] : [] as $date => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$by = [];
			foreach ( is_array( $entry['by'] ?? null ) ? $entry['by'] : [] as $method => $units ) {
				$by[ (string) $method ] = (int) $units;
			}
			$days[ (string) $date ] = [ 'used' => (int) ( $entry['used'] ?? 0 ), 'by' => $by, 'exhausted' => ! empty( $entry['exhausted'] ) ];
		}

		return [ 'limit' => $limit, 'days' => $days ];
	}

	/**
	 * @param array{limit: int, days: array<string, array{used: int, by: array<string, int>, exhausted: bool}>} $data
	 */
	private function save( array $data ): void {
		krsort( $data['days'] );
		$data['days'] = array_slice( $data['days'], 0, self::KEPT_DAYS, true );

		( $this->saver )( $data );
	}
}
