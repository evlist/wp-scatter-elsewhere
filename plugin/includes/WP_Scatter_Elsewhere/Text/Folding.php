<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Text;

/**
 * Folds text for comparisons: lower case, no accents, only letters and digits separated by single spaces.
 * It needs no extension (the intl extension is often missing).
 */
final class Folding {

	private const ACCENTS = [
		'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
		'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c', 'ċ' => 'c',
		'ď' => 'd', 'đ' => 'd',
		'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
		'ğ' => 'g', 'ĝ' => 'g', 'ġ' => 'g',
		'ĥ' => 'h',
		'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i', 'ı' => 'i',
		'ĵ' => 'j',
		'ķ' => 'k',
		'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ł' => 'l',
		'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
		'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o',
		'ŕ' => 'r', 'ř' => 'r',
		'ś' => 's', 'š' => 's', 'ş' => 's', 'ŝ' => 's',
		'ţ' => 't', 'ť' => 't',
		'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
		'ŵ' => 'w',
		'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
		'ź' => 'z', 'ž' => 'z', 'ż' => 'z',
		'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
	];

	public static function fold( string $text ): string {
		$text = strtr( mb_strtolower( $text, 'UTF-8' ), self::ACCENTS );
		$text = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Whether every word of the query is in the folded text. An empty query matches everything.
	 */
	public static function matches( string $text, string $query ): bool {
		$words = array_filter( explode( ' ', self::fold( $query ) ), static fn( string $word ): bool => '' !== $word );
		$text  = ' ' . self::fold( $text ) . ' ';

		foreach ( $words as $word ) {
			if ( ! str_contains( $text, $word ) ) {
				return false;
			}
		}

		return true;
	}
}
