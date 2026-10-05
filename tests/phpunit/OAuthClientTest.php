<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Everywhere\YouTube\OAuthClient;
use WP_Scatter_Everywhere\YouTube\OAuthException;

class OAuthClientTest extends TestCase {

	/**
	 * @var array<int, array{method: string, url: string, headers: array, form: ?array}>
	 */
	private array $requests = [];

	private function client( callable $respond ): OAuthClient {
		return new OAuthClient(
			'client-id',
			'client-secret',
			'https://example.org/wp-admin/admin-post.php?action=cb',
			function ( string $method, string $url, array $headers, ?array $form ) use ( $respond ): array {
				$this->requests[] = compact( 'method', 'url', 'headers', 'form' );

				return $respond();
			}
		);
	}

	public function test_builds_the_authorization_url(): void {
		$url = $this->client( static fn(): array => [] )->authorizationUrl( 'state-1' );

		$this->assertStringStartsWith( 'https://accounts.google.com/o/oauth2/v2/auth?', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'client-id', $query['client_id'] );
		$this->assertSame( 'https://example.org/wp-admin/admin-post.php?action=cb', $query['redirect_uri'] );
		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( 'offline', $query['access_type'] );
		$this->assertSame( 'consent', $query['prompt'] );
		$this->assertSame( 'state-1', $query['state'] );
		$this->assertSame( implode( ' ', OAuthClient::SCOPES ), $query['scope'] );
		$this->assertStringNotContainsString( 'client-secret', $url );
	}

	public function test_exchanges_a_code(): void {
		$tokens = $this->client( static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => 'a', 'expires_in' => 3599, 'refresh_token' => 'r' ] ] )->exchangeCode( 'the-code' );

		$this->assertSame( [ 'access_token' => 'a', 'expires_in' => 3599, 'refresh_token' => 'r' ], $tokens );
		$this->assertSame( 'POST', $this->requests[0]['method'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $this->requests[0]['url'] );
		$this->assertSame(
			[
				'code'          => 'the-code',
				'client_id'     => 'client-id',
				'client_secret' => 'client-secret',
				'redirect_uri'  => 'https://example.org/wp-admin/admin-post.php?action=cb',
				'grant_type'    => 'authorization_code',
			],
			$this->requests[0]['form']
		);
	}

	public function test_exchange_without_refresh_token_fails(): void {
		try {
			$this->client( static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => 'a', 'expires_in' => 3599 ] ] )->exchangeCode( 'c' );
			$this->fail( 'Expected an OAuthException.' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'no_refresh_token', $e->errorCode() );
		}
	}

	public function test_refreshes_a_token(): void {
		$tokens = $this->client( static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => 'new', 'expires_in' => 3000 ] ] )->refresh( 'r' );

		$this->assertSame( [ 'access_token' => 'new', 'expires_in' => 3000 ], $tokens );
		$this->assertSame( 'refresh_token', $this->requests[0]['form']['grant_type'] );
		$this->assertSame( 'r', $this->requests[0]['form']['refresh_token'] );
	}

	public function test_oauth_errors_are_typed(): void {
		try {
			$this->client( static fn(): array => [ 'status' => 400, 'body' => [ 'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.' ] ] )->refresh( 'r' );
			$this->fail( 'Expected an OAuthException.' );
		} catch ( OAuthException $e ) {
			$this->assertTrue( $e->isInvalidGrant() );
			$this->assertStringContainsString( 'Token has been expired or revoked.', $e->getMessage() );
		}
	}

	public function test_transport_and_http_errors_are_not_invalid_grant(): void {
		try {
			$this->client( static function (): array {
				throw new RuntimeException( 'timeout' );
			} )->refresh( 'r' );
			$this->fail( 'Expected an OAuthException.' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'transport', $e->errorCode() );
			$this->assertFalse( $e->isInvalidGrant() );
		}

		try {
			$this->client( static fn(): array => [ 'status' => 503, 'body' => [] ] )->refresh( 'r' );
			$this->fail( 'Expected an OAuthException.' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'http_503', $e->errorCode() );
		}
	}

	public function test_revoke_is_best_effort(): void {
		$this->assertTrue( $this->client( static fn(): array => [ 'status' => 200, 'body' => [] ] )->revoke( 'r' ) );
		$this->assertSame( 'https://oauth2.googleapis.com/revoke', $this->requests[0]['url'] );
		$this->assertSame( [ 'token' => 'r' ], $this->requests[0]['form'] );

		$this->assertFalse( $this->client( static fn(): array => [ 'status' => 400, 'body' => [] ] )->revoke( 'r' ) );
		$this->assertFalse(
			$this->client( static function (): array {
				throw new RuntimeException( 'down' );
			} )->revoke( 'r' )
		);
	}
}
