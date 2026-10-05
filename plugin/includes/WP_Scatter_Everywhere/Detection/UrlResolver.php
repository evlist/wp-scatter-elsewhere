<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\Detection;

/**
 * Resolves a possibly relative URL against a base URL (RFC 3986, simplified).
 */
final class UrlResolver {

	public function resolve( string $base, string $reference ): string {
		$reference = trim( $reference );

		if ( '' === $reference ) {
			return $base;
		}

		if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $reference ) ) {
			return $reference;
		}

		$parts = parse_url( $base );
		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return $reference;
		}

		$authority = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$basePath  = $parts['path'] ?? '/';

		if ( str_starts_with( $reference, '//' ) ) {
			return $parts['scheme'] . ':' . $reference;
		}

		if ( str_starts_with( $reference, '/' ) ) {
			return $authority . $this->removeDotSegments( $reference );
		}

		if ( str_starts_with( $reference, '?' ) ) {
			return $authority . $basePath . $reference;
		}

		if ( str_starts_with( $reference, '#' ) ) {
			return $authority . $basePath . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . $reference;
		}

		$directory = substr( $basePath, 0, (int) strrpos( $basePath, '/' ) + 1 );

		return $authority . $this->removeDotSegments( $directory . $reference );
	}

	/**
	 * Removes "." and ".." segments from the path part of a reference, keeping its query and fragment.
	 */
	private function removeDotSegments( string $reference ): string {
		$suffix = '';
		$cut    = strcspn( $reference, '?#' );
		if ( $cut < strlen( $reference ) ) {
			$suffix    = substr( $reference, $cut );
			$reference = substr( $reference, 0, $cut );
		}

		$output   = [];
		$segments = explode( '/', $reference );
		$last     = count( $segments ) - 1;

		foreach ( $segments as $index => $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				if ( '..' === $segment && count( $output ) > 1 ) {
					array_pop( $output );
				}
				if ( $index === $last ) {
					$output[] = '';
				}
				continue;
			}
			$output[] = $segment;
		}

		return implode( '/', $output ) . $suffix;
	}
}
