<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

/**
 * Readable messages for the errors of the YouTube Data API.
 */
final class ApiErrors {

	/**
	 * The "reason" of an error response, such as "quotaExceeded"; empty when there is none.
	 */
	public static function reason( string $body ): string {
		$data = json_decode( $body, true );

		return is_array( $data ) ? (string) ( $data['error']['errors'][0]['reason'] ?? '' ) : '';
	}

	public static function isQuota( int $status, string $body ): bool {
		return 403 === $status && in_array( self::reason( $body ), [ 'quotaExceeded', 'dailyLimitExceeded', 'rateLimitExceeded', 'userRateLimitExceeded' ], true );
	}

	public static function describe( int $status, string $body ): string {
		$data    = json_decode( $body, true );
		$message = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';

		return sprintf(
			/* translators: 1: HTTP status code, 2: error reason code (may be empty), 3: error message (may be empty). */
			__( 'YouTube answered with HTTP status %1$d (%2$s). %3$s', 'wp-scatter-elsewhere' ),
			$status,
			self::reason( $body ),
			$message
		);
	}
}
