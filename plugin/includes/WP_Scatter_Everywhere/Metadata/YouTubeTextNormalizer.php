<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\Metadata;

/**
 * Applies the YouTube limits and character rules to rendered text.
 */
final class YouTubeTextNormalizer {

	public const TITLE_MAX_CHARACTERS      = 100;
	public const DESCRIPTION_MAX_BYTES     = 5000;

	/**
	 * Normalizes a title; an empty result falls back to $fallback, then to a generic text.
	 */
	public function normalizeTitle( string $title, string $fallback = '' ): string {
		$clean = $this->cleanTitle( $title );

		if ( '' === $clean ) {
			$clean = $this->cleanTitle( $fallback );
		}

		if ( '' === $clean ) {
			$clean = __( 'Untitled video', 'wp-scatter-everywhere' );
		}

		if ( mb_strlen( $clean, 'UTF-8' ) > self::TITLE_MAX_CHARACTERS ) {
			$clean = rtrim( mb_substr( $clean, 0, self::TITLE_MAX_CHARACTERS - 1, 'UTF-8' ) ) . '…';
		}

		return $clean;
	}

	/**
	 * Normalizes a description: no angle brackets, at most 5000 bytes, never a split character.
	 */
	public function normalizeDescription( string $description ): string {
		$clean = str_replace( [ '<', '>' ], '', $description );
		$clean = str_replace( [ "\r\n", "\r" ], "\n", $clean );
		$clean = trim( $clean );

		return trim( mb_strcut( $clean, 0, self::DESCRIPTION_MAX_BYTES, 'UTF-8' ) );
	}

	private function cleanTitle( string $title ): string {
		$clean = str_replace( [ '<', '>' ], '', $title );
		$clean = preg_replace( '/\s+/u', ' ', $clean ) ?? $clean;

		return trim( $clean );
	}
}
