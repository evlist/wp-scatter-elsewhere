<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Everywhere\Detection\UrlResolver;

class UrlResolverTest extends TestCase {

	/**
	 * @dataProvider provideReferences
	 */
	public function test_resolves_references( string $reference, string $expected ): void {
		$base = 'https://example.org/blog/2026/post/?p=1';

		$this->assertSame( $expected, ( new UrlResolver() )->resolve( $base, $reference ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provideReferences(): array {
		return [
			'absolute'          => [ 'http://other.test/a.mp4', 'http://other.test/a.mp4' ],
			'protocol-relative' => [ '//cdn.example.org/a.mp4', 'https://cdn.example.org/a.mp4' ],
			'root-relative'     => [ '/wp-content/uploads/a.mp4', 'https://example.org/wp-content/uploads/a.mp4' ],
			'relative'          => [ 'a.mp4', 'https://example.org/blog/2026/post/a.mp4' ],
			'parent'            => [ '../a.mp4?x=1', 'https://example.org/blog/2026/a.mp4?x=1' ],
			'current dir'       => [ './a/./b.mp4', 'https://example.org/blog/2026/post/a/b.mp4' ],
			'query only'        => [ '?p=2', 'https://example.org/blog/2026/post/?p=2' ],
			'empty'             => [ '', 'https://example.org/blog/2026/post/?p=1' ],
		];
	}
}
