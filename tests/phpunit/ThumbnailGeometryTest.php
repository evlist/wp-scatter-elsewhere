<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Thumbnails\ThumbnailGeometry;

class ThumbnailGeometryTest extends TestCase {

	/**
	 * @dataProvider provideImages
	 */
	public function test_crop_box( int $width, int $height, array $expected ): void {
		$this->assertSame( $expected, ThumbnailGeometry::cropBox( $width, $height ) );
	}

	/**
	 * @return array<string, array{int, int, array{x: int, y: int, width: int, height: int}}>
	 */
	public static function provideImages(): array {
		return [
			'3:2 photo'        => [ 6000, 4000, [ 'x' => 0, 'y' => 312, 'width' => 6000, 'height' => 3375 ] ],
			'already 16:9'     => [ 1920, 1080, [ 'x' => 0, 'y' => 0, 'width' => 1920, 'height' => 1080 ] ],
			'wide panorama'    => [ 4000, 1000, [ 'x' => 1111, 'y' => 0, 'width' => 1777, 'height' => 1000 ] ],
			'portrait'         => [ 1000, 1500, [ 'x' => 0, 'y' => 469, 'width' => 1000, 'height' => 562 ] ],
			'square'           => [ 800, 800, [ 'x' => 0, 'y' => 175, 'width' => 800, 'height' => 450 ] ],
		];
	}

	public function test_the_crop_box_stays_inside_the_image_and_is_16_9(): void {
		foreach ( [ [ 6000, 4000 ], [ 4000, 1000 ], [ 1000, 1500 ], [ 641, 359 ] ] as [ $width, $height ] ) {
			$box = ThumbnailGeometry::cropBox( $width, $height );

			$this->assertGreaterThanOrEqual( 0, $box['x'] );
			$this->assertGreaterThanOrEqual( 0, $box['y'] );
			$this->assertLessThanOrEqual( $width, $box['x'] + $box['width'] );
			$this->assertLessThanOrEqual( $height, $box['y'] + $box['height'] );
			$this->assertEqualsWithDelta( 16 / 9, $box['width'] / max( 1, $box['height'] ), 0.01 );
		}
	}

	public function test_target_size_is_scaled_down_but_never_up(): void {
		$this->assertSame( [ 'width' => 1280, 'height' => 720 ], ThumbnailGeometry::targetSize( 6000, 3375 ) );
		$this->assertSame( [ 'width' => 1280, 'height' => 720 ], ThumbnailGeometry::targetSize( 1281, 720 ) );
		$this->assertSame( [ 'width' => 1280, 'height' => 720 ], ThumbnailGeometry::targetSize( 1280, 720 ) );
		$this->assertSame( [ 'width' => 800, 'height' => 450 ], ThumbnailGeometry::targetSize( 800, 450 ) );
	}
}
