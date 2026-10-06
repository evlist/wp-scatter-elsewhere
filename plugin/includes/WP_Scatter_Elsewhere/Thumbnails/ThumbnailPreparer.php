<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Thumbnails;

use Closure;

/**
 * Renders an image as a JPEG that YouTube accepts: it lowers the quality until the file is small enough.
 */
final class ThumbnailPreparer {

	/** YouTube refuses thumbnails above 2 MB. */
	public const MAX_BYTES = 2 * 1024 * 1024;

	public const QUALITIES = [ 90, 80, 70, 60, 50 ];

	/**
	 * @var Closure(string, int): (string|false)
	 */
	private Closure $renderer;

	/**
	 * @param Closure(string, int): (string|false) $renderer Crops and scales the image at a path and returns it as JPEG data
	 *                                                       of the given quality, or false when it cannot.
	 */
	public function __construct( Closure $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * @return string|null The JPEG data, or null when the image cannot be rendered or stays too large.
	 */
	public function prepare( string $path ): ?string {
		foreach ( self::QUALITIES as $quality ) {
			$data = ( $this->renderer )( $path, $quality );

			if ( false === $data || '' === $data ) {
				return null;
			}

			if ( strlen( $data ) <= self::MAX_BYTES ) {
				return $data;
			}
		}

		return null;
	}
}
