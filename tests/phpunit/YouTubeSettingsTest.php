<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Everywhere\Settings\YouTubeSettings;

class YouTubeSettingsTest extends TestCase {

	/**
	 * @var array<string, mixed>|null
	 */
	private ?array $saved = null;

	private function settings( mixed $stored ): YouTubeSettings {
		return new YouTubeSettings(
			static fn(): mixed => $stored,
			function ( array $value ): void {
				$this->saved = $value;
			}
		);
	}

	public function test_defaults_when_nothing_is_stored(): void {
		$settings = $this->settings( false );

		$this->assertSame( YouTubeSettings::STATUS_DISCONNECTED, $settings->status() );
		$this->assertFalse( $settings->hasCredentials() );
		$this->assertFalse( $settings->isConnected() );
		$this->assertSame( '', $settings->refreshToken() );
	}

	public function test_malformed_value_is_treated_as_empty(): void {
		$this->assertSame( YouTubeSettings::STATUS_DISCONNECTED, $this->settings( 'garbage' )->status() );
	}

	public function test_status_is_consistent_with_the_refresh_token(): void {
		$this->assertSame( YouTubeSettings::STATUS_DISCONNECTED, $this->settings( [ 'status' => 'connected' ] )->status() );
		$this->assertSame( YouTubeSettings::STATUS_CONNECTED, $this->settings( [ 'refresh_token' => 'r' ] )->status() );
		$this->assertSame( YouTubeSettings::STATUS_NEEDS_REAUTH, $this->settings( [ 'status' => 'needs_reauth' ] )->status() );
		$this->assertSame( YouTubeSettings::STATUS_DISCONNECTED, $this->settings( [ 'status' => 'bogus' ] )->status() );
	}

	public function test_saves_trimmed_credentials(): void {
		$this->settings( [] )->saveCredentials( '  id  ', '  secret ' );

		$this->assertSame( 'id', $this->saved['client_id'] );
		$this->assertSame( 'secret', $this->saved['client_secret'] );
	}

	public function test_empty_secret_keeps_the_stored_one_and_the_connection(): void {
		$this->settings( [ 'client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'r', 'channel_title' => 'Me' ] )
			->saveCredentials( 'id', '' );

		$this->assertSame( 'secret', $this->saved['client_secret'] );
		$this->assertSame( 'r', $this->saved['refresh_token'] );
		$this->assertSame( 'Me', $this->saved['channel_title'] );
	}

	public function test_changing_credentials_drops_the_connection(): void {
		$this->settings( [ 'client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'r', 'channel_title' => 'Me', 'status' => 'connected' ] )
			->saveCredentials( 'other', '' );

		$this->assertSame( 'other', $this->saved['client_id'] );
		$this->assertSame( '', $this->saved['refresh_token'] );
		$this->assertSame( '', $this->saved['channel_title'] );
		$this->assertSame( 'disconnected', $this->saved['status'] );
	}

	public function test_saves_a_connection(): void {
		$this->settings( [ 'client_id' => 'id', 'client_secret' => 'secret' ] )->saveConnection( 'refresh', 'UC1', 'My channel', 1760000000 );

		$this->assertSame( 'refresh', $this->saved['refresh_token'] );
		$this->assertSame( 'UC1', $this->saved['channel_id'] );
		$this->assertSame( 'My channel', $this->saved['channel_title'] );
		$this->assertSame( 1760000000, $this->saved['connected_at'] );
		$this->assertSame( 'connected', $this->saved['status'] );
		$this->assertSame( 'id', $this->saved['client_id'] );
	}

	public function test_needs_reauth_forgets_the_token_but_keeps_the_channel(): void {
		$this->settings( [ 'refresh_token' => 'r', 'channel_title' => 'Me', 'status' => 'connected' ] )->markNeedsReauth();

		$this->assertSame( '', $this->saved['refresh_token'] );
		$this->assertSame( 'Me', $this->saved['channel_title'] );
		$this->assertSame( 'needs_reauth', $this->saved['status'] );
	}

	public function test_clear_connection_keeps_credentials(): void {
		$this->settings( [ 'client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'r', 'channel_id' => 'UC1' ] )->clearConnection();

		$this->assertSame( 'id', $this->saved['client_id'] );
		$this->assertSame( 'secret', $this->saved['client_secret'] );
		$this->assertSame( '', $this->saved['refresh_token'] );
		$this->assertSame( '', $this->saved['channel_id'] );
		$this->assertSame( 'disconnected', $this->saved['status'] );
	}
}
