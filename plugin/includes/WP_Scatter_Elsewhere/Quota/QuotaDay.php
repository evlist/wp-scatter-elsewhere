<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Quota;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The day of the quota of YouTube: it starts at midnight Pacific Time, daylight saving time included.
 */
final class QuotaDay {

	private const ZONE = 'America/Los_Angeles';

	/**
	 * The date (Y-m-d) of the quota day of a Unix time.
	 */
	public static function of( int $time ): string {
		return ( new DateTimeImmutable( '@' . $time ) )->setTimezone( new DateTimeZone( self::ZONE ) )->format( 'Y-m-d' );
	}

	/**
	 * The Unix time at which the quota of the day of $time is reset.
	 */
	public static function nextReset( int $time ): int {
		$local = ( new DateTimeImmutable( '@' . $time ) )->setTimezone( new DateTimeZone( self::ZONE ) );

		return $local->modify( 'tomorrow midnight' )->getTimestamp();
	}
}
