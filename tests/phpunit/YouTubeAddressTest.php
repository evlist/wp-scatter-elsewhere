<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\YouTube\YouTubeAddress;

class YouTubeAddressTest extends TestCase {

	/**
	 * @dataProvider provideAddresses
	 */
	public function test_extracts_the_id( string $input, ?string $expected ): void {
		$this->assertSame( $expected, YouTubeAddress::extractId( $input ) );
	}

	/**
	 * @return array<string, array{string, ?string}>
	 */
	public static function provideAddresses(): array {
		return [
			'watch'                 => [ 'https://www.youtube.com/watch?v=9FzZpnEKL-s', '9FzZpnEKL-s' ],
			'watch with parameters' => [ 'https://www.youtube.com/watch?list=PLabc&v=9FzZpnEKL-s&t=42s', '9FzZpnEKL-s' ],
			'mobile'                => [ 'https://m.youtube.com/watch?v=9FzZpnEKL-s', '9FzZpnEKL-s' ],
			'no scheme'             => [ 'youtube.com/watch?v=9FzZpnEKL-s', '9FzZpnEKL-s' ],
			'short'                 => [ 'https://youtu.be/9FzZpnEKL-s', '9FzZpnEKL-s' ],
			'short with time'       => [ 'https://youtu.be/9FzZpnEKL-s?t=10', '9FzZpnEKL-s' ],
			'shorts'                => [ 'https://www.youtube.com/shorts/9FzZpnEKL-s', '9FzZpnEKL-s' ],
			'embed'                 => [ 'https://www.youtube.com/embed/9FzZpnEKL-s', '9FzZpnEKL-s' ],
			'nocookie embed'        => [ 'https://www.youtube-nocookie.com/embed/9FzZpnEKL-s', '9FzZpnEKL-s' ],
			'live'                  => [ 'https://www.youtube.com/live/9FzZpnEKL-s?feature=share', '9FzZpnEKL-s' ],
			'bare id'               => [ '9FzZpnEKL-s', '9FzZpnEKL-s' ],
			'surrounding spaces'    => [ "  https://youtu.be/9FzZpnEKL-s \n", '9FzZpnEKL-s' ],
			'id with underscore'    => [ 'https://youtu.be/abcDEF_-123', 'abcDEF_-123' ],
			'other host'            => [ 'https://example.org/watch?v=9FzZpnEKL-s', null ],
			'lookalike host'        => [ 'https://youtube.com.evil.test/watch?v=9FzZpnEKL-s', null ],
			'channel'               => [ 'https://www.youtube.com/@channel', null ],
			'playlist'              => [ 'https://www.youtube.com/playlist?list=PLLhpqoEh2pR1mkoHgHV0lddEr2a5Pde1l', null ],
			'too short id'          => [ 'https://youtu.be/9FzZpnEKL', null ],
			'too long id'           => [ 'https://youtu.be/9FzZpnEKL-sx', null ],
			'empty'                 => [ '', null ],
			'text'                  => [ 'my video', null ],
		];
	}
}
