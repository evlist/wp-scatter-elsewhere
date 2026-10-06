<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Metadata;

/**
 * Cleans keywords and keeps them within the limit of YouTube.
 */
final class KeywordNormalizer {

	/** To the best of our knowledge, the keywords of a video may hold 500 characters in total. */
	public const MAX_CHARACTERS = 500;

	/**
	 * Cleans the keywords and keeps those that fit, in order.
	 *
	 * @param string[] $keywords
	 * @return array{kept: string[], dropped: string[]}
	 */
	public function fit( array $keywords ): array {
		return $this->add( [], $keywords );
	}

	/**
	 * Adds keywords to existing ones. The existing keywords are always kept; the new ones are added
	 * while they fit and are not already there (case-insensitive).
	 *
	 * @param string[] $existing
	 * @param string[] $new
	 * @return array{kept: string[], dropped: string[]} "kept" is the whole resulting list.
	 */
	public function add( array $existing, array $new ): array {
		$kept    = array_values( $existing );
		$known   = [];
		foreach ( $kept as $keyword ) {
			$known[ mb_strtolower( $keyword, 'UTF-8' ) ] = true;
		}

		$dropped = [];
		foreach ( $new as $keyword ) {
			$keyword = $this->clean( $keyword );
			$key     = mb_strtolower( $keyword, 'UTF-8' );

			if ( '' === $keyword || isset( $known[ $key ] ) ) {
				continue;
			}

			$candidate = array_merge( $kept, [ $keyword ] );
			if ( $this->length( $candidate ) > self::MAX_CHARACTERS ) {
				$dropped[] = $keyword;
				continue;
			}

			$kept[]        = $keyword;
			$known[ $key ] = true;
		}

		return [ 'kept' => $kept, 'dropped' => $dropped ];
	}

	/**
	 * Characters counted by YouTube: a keyword with a space is quoted, and keywords are separated by commas.
	 *
	 * @param string[] $keywords
	 */
	public function length( array $keywords ): int {
		$total = 0;
		foreach ( $keywords as $keyword ) {
			$total += mb_strlen( $keyword, 'UTF-8' ) + ( str_contains( $keyword, ' ' ) ? 2 : 0 );
		}

		return $total + max( 0, count( $keywords ) - 1 );
	}

	private function clean( string $keyword ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', str_replace( [ '<', '>' ], '', $keyword ) ) );
	}
}
