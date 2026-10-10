<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Outdooractive;

/**
 * Chooses the folder of the ZIP, that is the activity Outdooractive gives to the trace: the value set on the
 * file, else the one that goes with the end of its name (20261008-vanlife.gpx), else the default one.
 */
final class ActivityResolver {

	/**
	 * @param ?string              $explicit  The activity stored on the file (its attachment), if any.
	 * @param array<string,string> $suffixes  Folder by suffix of the file name, in lower case.
	 */
	public function resolve( ?string $explicit, string $fileName, array $suffixes, string $default ): string {
		$explicit = $this->clean( (string) $explicit );
		if ( '' !== $explicit ) {
			return $explicit;
		}

		$base = strtolower( pathinfo( $fileName, PATHINFO_FILENAME ) );
		foreach ( $suffixes as $suffix => $folder ) {
			$suffix = strtolower( (string) $suffix );
			if ( '' !== $suffix && ( $base === $suffix || str_ends_with( $base, '-' . $suffix ) || str_ends_with( $base, '_' . $suffix ) ) ) {
				return $this->clean( $folder );
			}
		}

		return $this->clean( $default );
	}

	/**
	 * A folder name is a single, plain segment: no path, no dots at the ends, no control characters.
	 */
	public function clean( string $folder ): string {
		$folder = (string) preg_replace( '/[\x00-\x1f\/\\\\:*?"<>|]+/u', ' ', $folder );

		return trim( (string) preg_replace( '/\s+/u', ' ', $folder ), " .\t" );
	}
}
