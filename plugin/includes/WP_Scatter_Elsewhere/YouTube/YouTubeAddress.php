<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

use WP_Scatter_Elsewhere\Publication\Publication;

/**
 * Extracts the id of a video from the addresses people copy.
 */
final class YouTubeAddress {

	private const HOSTS = [ 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be', 'www.youtu.be' ];

	/**
	 * The 11-character id of a video from a watch, short, shorts, embed or live address, or from the bare id.
	 */
	public static function extractId( string $input ): ?string {
		$input = trim( $input );

		if ( Publication::isValidYouTubeId( $input ) ) {
			return $input;
		}

		if ( ! str_contains( $input, '://' ) ) {
			$input = 'https://' . $input;
		}

		$parts = parse_url( $input );
		if ( false === $parts || ! isset( $parts['host'] ) || ! in_array( strtolower( $parts['host'] ), self::HOSTS, true ) ) {
			return null;
		}

		$host     = strtolower( $parts['host'] );
		$segments = array_values( array_filter( explode( '/', (string) ( $parts['path'] ?? '' ) ), static fn( string $segment ): bool => '' !== $segment ) );

		if ( str_ends_with( $host, 'youtu.be' ) ) {
			$candidate = $segments[0] ?? '';
		} elseif ( in_array( $segments[0] ?? '', [ 'shorts', 'embed', 'live', 'v' ], true ) ) {
			$candidate = $segments[1] ?? '';
		} elseif ( 'watch' === ( $segments[0] ?? '' ) ) {
			parse_str( (string) ( $parts['query'] ?? '' ), $query );
			$candidate = (string) ( $query['v'] ?? '' );
		} else {
			return null;
		}

		return Publication::isValidYouTubeId( $candidate ) ? $candidate : null;
	}
}
