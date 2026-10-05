<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Everywhere\Settings\YouTubeSettings;
use WP_Scatter_Everywhere\YouTube\ChannelClient;
use WP_Scatter_Everywhere\YouTube\ConnectionService;
use WP_Scatter_Everywhere\YouTube\OAuthClient;
use WP_Scatter_Everywhere\YouTube\OAuthException;
use WP_Scatter_Everywhere\YouTube\OAuthStateStore;

class ConnectionServiceTest extends TestCase {

	/**
	 * @var array<string, mixed>
	 */
	private array $stored = [ 'client_id' => 'id', 'client_secret' => 'secret' ];

	/**
	 * @var string[]
	 */
	private array $calls = [];

	private int $tokenCacheCleared = 0;

	private ?string $state = null;

	private function service( array $stored = null ): ConnectionService {
		$this->stored = $stored ?? $this->stored;
		$http         = function ( string $method, string $url, array $headers, ?array $form ): array {
			$this->calls[] = $url;

			if ( str_contains( $url, '/token' ) ) {
				return [ 'status' => 200, 'body' => [ 'access_token' => 'access', 'expires_in' => 3600, 'refresh_token' => 'refresh' ] ];
			}
			if ( str_contains( $url, '/channels' ) ) {
				return [ 'status' => 200, 'body' => [ 'items' => [ [ 'id' => 'UC1', 'snippet' => [ 'title' => 'My channel' ] ] ] ] ];
			}

			return [ 'status' => 200, 'body' => [] ];
		};

		return new ConnectionService(
			new YouTubeSettings(
				fn(): array => $this->stored,
				function ( array $value ): void {
					$this->stored = $value;
				}
			),
			new OAuthClient( 'id', 'secret', 'https://example.org/cb', $http ),
			new ChannelClient( $http ),
			new OAuthStateStore(
				fn( int $user ): ?string => $this->state,
				function ( int $user, string $state, int $ttl ): void {
					$this->state = $state;
				},
				function ( int $user ): void {
					$this->state = null;
				},
				fn(): string => 'abc'
			),
			function (): void {
				++$this->tokenCacheCleared;
			},
			fn(): int => 1760000000
		);
	}

	public function test_start_requires_saved_credentials(): void {
		$this->expectException( OAuthException::class );

		$this->service( [] )->startAuthorization( 1 );
	}

	public function test_start_returns_the_google_url_with_a_fresh_state(): void {
		$url = $this->service()->startAuthorization( 1 );

		$this->assertStringContainsString( 'accounts.google.com', $url );
		$this->assertStringContainsString( 'state=abc', $url );
		$this->assertSame( 'abc', $this->state );
	}

	public function test_complete_stores_the_connection(): void {
		$service = $this->service();
		$service->startAuthorization( 1 );

		$service->completeAuthorization( 1, [ 'code' => 'the-code', 'state' => 'abc' ] );

		$this->assertSame( 'refresh', $this->stored['refresh_token'] );
		$this->assertSame( 'UC1', $this->stored['channel_id'] );
		$this->assertSame( 'My channel', $this->stored['channel_title'] );
		$this->assertSame( 1760000000, $this->stored['connected_at'] );
		$this->assertSame( 'connected', $this->stored['status'] );
		$this->assertSame( 1, $this->tokenCacheCleared );
	}

	public function test_callback_with_a_bad_state_is_rejected_without_any_request(): void {
		$service = $this->service();
		$service->startAuthorization( 1 );

		try {
			$service->completeAuthorization( 1, [ 'code' => 'c', 'state' => 'forged' ] );
			$this->fail( 'Expected an OAuthException.' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'invalid_state', $e->errorCode() );
			$this->assertSame( [], $this->calls );
			$this->assertArrayNotHasKey( 'refresh_token', $this->stored );
		}
	}

	public function test_callback_without_a_state_or_code_is_rejected(): void {
		$service = $this->service();

		foreach ( [ [], [ 'code' => 'c' ] ] as $query ) {
			$service->startAuthorization( 1 );
			try {
				$service->completeAuthorization( 1, $query );
				$this->fail( 'Expected an OAuthException.' );
			} catch ( OAuthException $e ) {
				$this->assertSame( 'invalid_state', $e->errorCode() );
			}
		}

		$service->startAuthorization( 1 );
		try {
			$service->completeAuthorization( 1, [ 'state' => 'abc' ] );
			$this->fail( 'Expected an OAuthException.' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'missing_code', $e->errorCode() );
		}
	}

	public function test_callback_reports_a_refusal_of_the_user(): void {
		$service = $this->service();
		$service->startAuthorization( 1 );

		try {
			$service->completeAuthorization( 1, [ 'error' => 'access_denied', 'state' => 'abc' ] );
			$this->fail( 'Expected an OAuthException.' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'access_denied', $e->errorCode() );
		}
	}

	public function test_the_state_cannot_be_replayed(): void {
		$service = $this->service();
		$service->startAuthorization( 1 );
		$service->completeAuthorization( 1, [ 'code' => 'c', 'state' => 'abc' ] );

		$this->expectException( OAuthException::class );
		$service->completeAuthorization( 1, [ 'code' => 'c', 'state' => 'abc' ] );
	}

	public function test_disconnect_revokes_then_clears_and_keeps_credentials(): void {
		$service = $this->service( [ 'client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'refresh', 'channel_title' => 'Me', 'status' => 'connected' ] );

		$service->disconnect();

		$this->assertSame( [ 'https://oauth2.googleapis.com/revoke' ], $this->calls );
		$this->assertSame( '', $this->stored['refresh_token'] );
		$this->assertSame( 'id', $this->stored['client_id'] );
		$this->assertSame( 'secret', $this->stored['client_secret'] );
		$this->assertSame( 1, $this->tokenCacheCleared );
	}

	public function test_disconnect_clears_locally_even_when_revocation_fails(): void {
		$stored  = [ 'client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'refresh', 'status' => 'connected' ];
		$failing = new OAuthClient(
			'id',
			'secret',
			'https://example.org/cb',
			static function (): array {
				throw new RuntimeException( 'offline' );
			}
		);
		$settings = new YouTubeSettings(
			static fn(): array => $stored,
			function ( array $value ): void {
				$this->stored = $value;
			}
		);
		$service  = new ConnectionService(
			$settings,
			$failing,
			new ChannelClient( static fn(): array => [ 'status' => 500, 'body' => [] ] ),
			new OAuthStateStore( static fn() => null, static function (): void {}, static function (): void {}, static fn(): string => 'x' ),
			static function (): void {},
			static fn(): int => 0
		);

		$service->disconnect();

		$this->assertSame( '', $this->stored['refresh_token'] );
		$this->assertSame( 'disconnected', $this->stored['status'] );
	}
}
