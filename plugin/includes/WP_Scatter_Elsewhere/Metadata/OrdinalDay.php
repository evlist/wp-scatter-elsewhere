<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Metadata;

/**
 * The day of the month as it is written in a date in a language: "1er" in French, "1st", "2nd" in
 * English. Other languages get the plain number.
 */
final class OrdinalDay {

	public static function format( int $day, string $locale ): string {
		$language = strtolower( substr( $locale, 0, 2 ) );

		switch ( $language ) {
			case 'fr':
				return 1 === $day ? '1er' : (string) $day;
			case 'en':
				return $day . self::englishSuffix( $day );
			default:
				return (string) $day;
		}
	}

	private static function englishSuffix( int $day ): string {
		if ( $day >= 11 && $day <= 13 ) {
			return 'th';
		}

		switch ( $day % 10 ) {
			case 1:
				return 'st';
			case 2:
				return 'nd';
			case 3:
				return 'rd';
			default:
				return 'th';
		}
	}
}
