<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Metadata\OrdinalDay;
use WP_Scatter_Elsewhere\Metadata\PostData;
use WP_Scatter_Elsewhere\Metadata\TemplateParser;
use WP_Scatter_Elsewhere\Metadata\TemplateRenderer;

class OrdinalDayTest extends TestCase {

	public function test_french_only_the_first_is_ordinal(): void {
		$this->assertSame( '1er', OrdinalDay::format( 1, 'fr_FR' ) );
		$this->assertSame( '2', OrdinalDay::format( 2, 'fr_CA' ) );
		$this->assertSame( '31', OrdinalDay::format( 31, 'fr_FR' ) );
	}

	public function test_english_suffixes(): void {
		$expected = [ 1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 11 => '11th', 12 => '12th', 13 => '13th', 21 => '21st', 22 => '22nd', 23 => '23rd', 30 => '30th', 31 => '31st' ];
		foreach ( $expected as $day => $text ) {
			$this->assertSame( $text, OrdinalDay::format( $day, 'en_GB' ), (string) $day );
		}
	}

	public function test_other_languages_and_unknown_locale_get_the_number(): void {
		$this->assertSame( '1', OrdinalDay::format( 1, 'de_DE' ) );
		$this->assertSame( '1', OrdinalDay::format( 1, '' ) );
	}

	public function test_the_placeholder_uses_the_locale_of_the_site(): void {
		$post   = new PostData( 'T', '', 'https://example.org/', new DateTimeImmutable( '2026-10-01 10:00:00' ), 'Eric', [] );
		$format = static fn( DateTimeImmutable $date, ?string $format ): string => $date->format( $format ?? 'Y-m-d' );

		$fr = new TemplateRenderer( new TemplateParser(), $format, static fn(): string => 'fr_FR' );
		$this->assertSame( '1er 2026', $fr->render( '{ordinal_day} {date:Y}', $post ) );

		$default = new TemplateRenderer( new TemplateParser(), $format );
		$this->assertSame( '1', $default->render( '{ordinal_day}', $post ) );
	}
}
