<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Thumbnails;

/**
 * The size and framing of a YouTube thumbnail.
 */
final class ThumbnailGeometry {

	public const WIDTH  = 1280;
	public const HEIGHT = 720;

	/**
	 * The largest centred 16:9 rectangle of an image.
	 *
	 * @return array{x: int, y: int, width: int, height: int}
	 */
	public static function cropBox( int $width, int $height ): array {
		if ( $width * self::HEIGHT >= $height * self::WIDTH ) {
			// Wider than 16:9: cut the sides.
			$cropWidth  = intdiv( $height * self::WIDTH, self::HEIGHT );
			$cropHeight = $height;
		} else {
			// Taller than 16:9: cut the top and the bottom.
			$cropWidth  = $width;
			$cropHeight = intdiv( $width * self::HEIGHT, self::WIDTH );
		}

		return [
			'x'      => intdiv( $width - $cropWidth, 2 ),
			'y'      => intdiv( $height - $cropHeight, 2 ),
			'width'  => $cropWidth,
			'height' => $cropHeight,
		];
	}

	/**
	 * The size of the thumbnail made from a cropped image: scaled down to 1280 x 720, never scaled up.
	 *
	 * @return array{width: int, height: int}
	 */
	public static function targetSize( int $croppedWidth, int $croppedHeight ): array {
		if ( $croppedWidth <= self::WIDTH ) {
			return [ 'width' => $croppedWidth, 'height' => $croppedHeight ];
		}

		return [ 'width' => self::WIDTH, 'height' => self::HEIGHT ];
	}
}
