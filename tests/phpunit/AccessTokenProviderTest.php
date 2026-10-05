<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;
use WP_Scatter_Elsewhere\YouTube\AccessTokenProvider;
use WP_Scatter_Elsewhere\YouTube\NotConnectedException;
use WP_Scatter_Elsewhere\YouTube\OAuthClient;
use WP_Scatter_Elsewhere\YouTube\OAuthException;
use WP_Scatter_Elsewhere\YouTube\ReauthorizationRequiredException;

class AccessTokenProviderTest extends TestCase {

	private int $now = 1000;

	private ?array $cache = null;

	private int $refreshCalls = 0;

	/**
	 * @var array<string, mixed>
	 */
	private array $stored = [];

	private function provider( array $stored, callable $respond ): AccessTokenProvider {
		$this->stored = $stored;
		$settings     = new YouTubeSettings(
			fn(): array => $this->stored,
			function ( array $value ): void {
				$this->stored = $value;
			}
		);
		$oauth        = new OAuthClient(
			'id',
			'secret',
			'https://example.org/cb',
			function () use ( $respond ): array {
				++$this->refreshCalls;

				return $respond();
			}
		);

		return new AccessTokenProvider(
			$settings,
			$oauth,
			fn(): ?array => $this->cache,
			function ( array $value, int $ttl ): void {
				$this->cache = $value;
			},
			function (): void {
				$this->cache = null;
			},
			fn(): int => $this->now
		);
	}

	private static function ok( string $token = 'fresh', int $expiresIn = 3600 ): callable {
		return static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => $token, 'expires_in' => $expiresIn ] ];
	}

	public function test_refreshes_when_nothing_is_cached_and_caches_the_result(): void {
		$provider = $this->provider( [ 'refresh_token' => 'r' ], self::ok() );

		$this->assertSame( 'fresh', $provider->getAccessToken() );
		$this->assertSame( [ 'token' => 'fresh', 'expires_at' => 4600 ], $this->cache );
	}

	public function test_returns_the_cached_token_until_one_minute_before_expiry(): void {
		$provider    = $this->provider( [ 'refresh_token' => 'r' ], self::ok( 'new' ) );
		$this->cache = [ 'token' => 'cached', 'expires_at' => 1061 ];

		$this->assertSame( 'cached', $provider->getAccessToken() );
		$this->assertSame( 0, $this->refreshCalls );

		$this->now = 1001;
		$this->assertSame( 'new', $provider->getAccessToken() );
		$this->assertSame( 1, $this->refreshCalls );
	}

	public function test_refreshes_an_expired_token(): void {
		$provider    = $this->provider( [ 'refresh_token' => 'r' ], self::ok( 'new' ) );
		$this->cache = [ 'token' => 'old', 'expires_at' => 900 ];

		$this->assertSame( 'new', $provider->getAccessToken() );
	}

	public function test_not_connected(): void {
		$this->expectException( NotConnectedException::class );

		$this->provider( [], self::ok() )->getAccessToken();
	}

	public function test_invalid_grant_marks_the_connection_as_needing_reauthorisation(): void {
		$provider    = $this->provider( [ 'refresh_token' => 'r', 'channel_title' => 'Me' ], static fn(): array => [ 'status' => 400, 'body' => [ 'error' => 'invalid_grant' ] ] );
		$this->cache = [ 'token' => 'old', 'expires_at' => 900 ];

		try {
			$provider->getAccessToken();
			$this->fail( 'Expected a ReauthorizationRequiredException.' );
		} catch ( ReauthorizationRequiredException $e ) {
			$this->assertSame( 'needs_reauth', $this->stored['status'] );
			$this->assertSame( '', $this->stored['refresh_token'] );
			$this->assertNull( $this->cache );
		}

		$this->expectException( ReauthorizationRequiredException::class );
		$this->provider( $this->stored, self::ok() )->getAccessToken();
	}

	public function test_other_failures_leave_the_connection_untouched(): void {
		$provider = $this->provider( [ 'refresh_token' => 'r' ], static fn(): array => [ 'status' => 503, 'body' => [] ] );

		try {
			$provider->getAccessToken();
			$this->fail( 'Expected an OAuthException.' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'r', $this->stored['refresh_token'] );
			$this->assertArrayNotHasKey( 'status', $this->stored );
		}
	}
}
