<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Thumbnails\ThumbnailPreparer;

class ThumbnailPreparerTest extends TestCase {

	public function test_returns_the_first_rendering_that_fits(): void {
		$qualities = [];
		$preparer  = new ThumbnailPreparer(
			static function ( string $path, int $quality ) use ( &$qualities ): string {
				$qualities[] = $quality;

				return str_repeat( 'x', 90 === $quality ? ThumbnailPreparer::MAX_BYTES + 1 : ThumbnailPreparer::MAX_BYTES );
			}
		);

		$data = $preparer->prepare( '/a.jpg' );

		$this->assertSame( ThumbnailPreparer::MAX_BYTES, strlen( (string) $data ) );
		$this->assertSame( [ 90, 80 ], $qualities );
	}

	public function test_a_small_image_is_rendered_once_at_the_best_quality(): void {
		$qualities = [];
		$preparer  = new ThumbnailPreparer(
			static function ( string $path, int $quality ) use ( &$qualities ): string {
				$qualities[] = $quality;

				return 'small';
			}
		);

		$this->assertSame( 'small', $preparer->prepare( '/a.jpg' ) );
		$this->assertSame( [ 90 ], $qualities );
	}

	public function test_gives_up_when_nothing_fits(): void {
		$qualities = [];
		$preparer  = new ThumbnailPreparer(
			static function ( string $path, int $quality ) use ( &$qualities ): string {
				$qualities[] = $quality;

				return str_repeat( 'x', ThumbnailPreparer::MAX_BYTES + 1 );
			}
		);

		$this->assertNull( $preparer->prepare( '/a.jpg' ) );
		$this->assertSame( [ 90, 80, 70, 60, 50 ], $qualities );
	}

	public function test_a_renderer_failure_stops_at_once(): void {
		$calls    = 0;
		$preparer = new ThumbnailPreparer(
			static function () use ( &$calls ): bool {
				++$calls;

				return false;
			}
		);

		$this->assertNull( $preparer->prepare( '/a.jpg' ) );
		$this->assertSame( 1, $calls );
		$this->assertNull( ( new ThumbnailPreparer( static fn(): string => '' ) )->prepare( '/a.jpg' ) );
	}
}
