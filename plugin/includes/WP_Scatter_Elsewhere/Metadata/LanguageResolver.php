<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Metadata;

/**
 * Converts WordPress locales and user input to the language codes of YouTube.
 */
final class LanguageResolver {

	/** Languages for which YouTube distinguishes regions. */
	private const REGIONAL = [ 'zh', 'pt' ];

	/**
	 * Whether a string looks like a BCP-47 language code (letters, then optional "-" subtags).
	 */
	public static function isValid( string $language ): bool {
		return 1 === preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language );
	}

	/**
	 * Derives the YouTube language of a WordPress locale such as "fr_FR", or null when unusable.
	 */
	public static function fromLocale( string $locale ): ?string {
		$parts    = preg_split( '/[_-]/', trim( $locale ) );
		$language = strtolower( (string) ( $parts[0] ?? '' ) );

		if ( 1 !== preg_match( '/^[a-z]{2,3}$/', $language ) ) {
			return null;
		}

		$region = (string) ( $parts[1] ?? '' );
		if ( in_array( $language, self::REGIONAL, true ) && 1 === preg_match( '/^[A-Za-z]{2}$/', $region ) ) {
			return $language . '-' . strtoupper( $region );
		}

		return $language;
	}
}
