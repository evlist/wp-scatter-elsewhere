<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

use Closure;
use WP_Scatter_Elsewhere\Quota\CostTable;
use WP_Scatter_Elsewhere\Quota\QuotaMeter;

/**
 * Decorates the HTTP closure of the YouTube clients so that every request is counted by the quota meter.
 */
final class MeteredHttp {

	private const EXHAUSTED_REASONS = [ 'quotaExceeded', 'dailyLimitExceeded' ];

	/**
	 * @param Closure(string, string, array<string, string>, string): array{status: int, headers: array<string, string>, body: string} $http
	 * @return Closure(string, string, array<string, string>, string): array{status: int, headers: array<string, string>, body: string}
	 */
	public static function wrap( Closure $http, QuotaMeter $meter ): Closure {
		return static function ( string $method, string $url, array $headers, string $body ) use ( $http, $meter ): array {
			// A transport error (RuntimeException) reaches nobody and costs nothing.
			$response = $http( $method, $url, $headers, $body );
			$cost     = CostTable::identify( $method, $url );

			if ( '' !== $cost['method'] ) {
				$meter->record( $cost['method'], $cost['units'] );
			}

			if ( 403 === $response['status'] && in_array( ApiErrors::reason( $response['body'] ), self::EXHAUSTED_REASONS, true ) ) {
				$meter->markExhausted();
			}

			return $response;
		};
	}
}
