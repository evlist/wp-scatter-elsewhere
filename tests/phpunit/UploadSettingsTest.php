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

	public function test_default_privacy_is_private(): void {
		$this->assertSame( 'private', $this->settings( false )->defaultPrivacy() );
		$this->assertSame( 'private', $this->settings( [] )->defaultPrivacy() );
		$this->assertSame( 'private', $this->settings( 'garbage' )->defaultPrivacy() );
		$this->assertSame( 'private', $this->settings( [ 'default_privacy' => 'everyone' ] )->defaultPrivacy() );
	}

	public function test_returns_the_stored_privacy(): void {
		$this->assertSame( 'unlisted', $this->settings( [ 'default_privacy' => 'unlisted' ] )->defaultPrivacy() );
		$this->assertSame( 'public', $this->settings( [ 'default_privacy' => 'public' ] )->defaultPrivacy() );
	}

	public function test_saves_a_valid_privacy_only(): void {
		$settings = $this->settings( [] );

		$settings->saveDefaultPrivacy( 'unlisted' );
		$this->assertSame( [ 'default_privacy' => 'unlisted' ], $this->saved );

		$this->saved = null;
		try {
			$settings->saveDefaultPrivacy( 'everyone' );
			$this->fail( 'Expected an InvalidArgumentException.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertNull( $this->saved );
		}
	}
}
