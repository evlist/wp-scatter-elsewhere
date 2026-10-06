<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Settings\UploadSettings;

class UploadSettingsTest extends TestCase {

	private ?array $saved = null;

	private function settings( mixed $stored ): UploadSettings {
		return new UploadSettings(
			static fn(): mixed => $stored,
			function ( array $value ): void {
				$this->saved = $value;
			}
		);
	}

	public function test_defaults(): void {
		foreach ( [ false, [], 'garbage' ] as $stored ) {
			$settings = $this->settings( $stored );

			$this->assertSame( 'private', $settings->defaultPrivacy() );
			$this->assertSame( 'youtube', $settings->defaultLicense() );
			$this->assertSame( '', $settings->language() );
			$this->assertTrue( $settings->sendsRecordingDate() );
			$this->assertSame( 'sbv', $settings->subtitleFormat() );
		}
	}

	public function test_invalid_stored_values_fall_back_to_the_defaults(): void {
		$settings = $this->settings( [ 'default_privacy' => 'everyone', 'default_license' => 'mit', 'language' => 'not a language', 'send_recording_date' => 'yes' ] );

		$this->assertSame( 'private', $settings->defaultPrivacy() );
		$this->assertSame( 'youtube', $settings->defaultLicense() );
		$this->assertSame( '', $settings->language() );
		$this->assertTrue( $settings->sendsRecordingDate() );
	}

	public function test_returns_the_stored_values(): void {
		$settings = $this->settings( [ 'default_privacy' => 'unlisted', 'default_license' => 'creativeCommon', 'language' => 'pt-BR', 'send_recording_date' => false ] );

		$this->assertSame( 'unlisted', $settings->defaultPrivacy() );
		$this->assertSame( 'creativeCommon', $settings->defaultLicense() );
		$this->assertSame( 'pt-BR', $settings->language() );
		$this->assertFalse( $settings->sendsRecordingDate() );
	}

	public function test_saves_valid_values(): void {
		$this->settings( [] )->save( 'public', 'creativeCommon', ' fr ', false, 'vtt' );

		$this->assertSame(
			[ 'subtitle_format' => 'vtt', 'default_privacy' => 'public', 'default_license' => 'creativeCommon', 'language' => 'fr', 'send_recording_date' => false ],
			$this->saved
		);
	}

	public function test_the_subtitle_format_is_validated_and_defaults_to_sbv(): void {
		$this->assertSame( 'vtt', $this->settings( [ 'subtitle_format' => 'vtt' ] )->subtitleFormat() );
		$this->assertSame( 'srt', $this->settings( [ 'subtitle_format' => 'srt' ] )->subtitleFormat() );
		$this->assertSame( 'sbv', $this->settings( [ 'subtitle_format' => 'doc' ] )->subtitleFormat() );

		$this->expectException( InvalidArgumentException::class );
		$this->settings( [] )->save( 'private', 'youtube', '', true, 'doc' );
	}

	public function test_an_empty_language_is_valid_and_means_the_site_language(): void {
		$this->settings( [] )->save( 'private', 'youtube', '', true );

		$this->assertSame( '', $this->saved['language'] );
	}

	/**
	 * @dataProvider provideInvalidValues
	 */
	public function test_nothing_is_saved_when_a_value_is_invalid( string $privacy, string $license, string $language ): void {
		try {
			$this->settings( [] )->save( $privacy, $license, $language, true );
			$this->fail( 'Expected an InvalidArgumentException.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
			$this->assertNull( $this->saved );
		}
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function provideInvalidValues(): array {
		return [
			'privacy'  => [ 'everyone', 'youtube', 'fr' ],
			'license'  => [ 'private', 'mit', 'fr' ],
			'language' => [ 'private', 'youtube', 'french language' ],
			'digits'   => [ 'private', 'youtube', '12' ],
		];
	}
}
