<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Metadata\LanguageResolver;

class LanguageResolverTest extends TestCase {

	/**
	 * @dataProvider provideLocales
	 */
	public function test_derives_the_language_of_a_locale( string $locale, ?string $expected ): void {
		$this->assertSame( $expected, LanguageResolver::fromLocale( $locale ) );
	}

	/**
	 * @return array<string, array{string, ?string}>
	 */
	public static function provideLocales(): array {
		return [
			'french'            => [ 'fr_FR', 'fr' ],
			'american english'  => [ 'en_US', 'en' ],
			'formal german'     => [ 'de_DE_formal', 'de' ],
			'language only'     => [ 'ca', 'ca' ],
			'three letters'     => [ 'ast', 'ast' ],
			'simplified chinese' => [ 'zh_CN', 'zh-CN' ],
			'traditional chinese' => [ 'zh_TW', 'zh-TW' ],
			'brazilian'         => [ 'pt_BR', 'pt-BR' ],
			'portuguese'        => [ 'pt', 'pt' ],
			'dash separator'    => [ 'fr-CA', 'fr' ],
			'empty'             => [ '', null ],
			'garbage'           => [ '12_34', null ],
		];
	}

	public function test_validates_language_codes(): void {
		foreach ( [ 'fr', 'en', 'pt-BR', 'zh-Hans', 'ast' ] as $valid ) {
			$this->assertTrue( LanguageResolver::isValid( $valid ), $valid );
		}
		foreach ( [ '', 'f', 'french', 'fr_FR', 'fr-', '12', 'fr FR' ] as $invalid ) {
			$this->assertFalse( LanguageResolver::isValid( $invalid ), $invalid );
		}
	}
}
