<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;
use WP_Scatter_Elsewhere\Thumbnails\ThumbnailPreparer;
use WP_Scatter_Elsewhere\Thumbnails\ThumbnailService;
use WP_Scatter_Elsewhere\YouTube\AccessTokenProvider;
use WP_Scatter_Elsewhere\YouTube\OAuthClient;
use WP_Scatter_Elsewhere\YouTube\ThumbnailClient;
use WP_Scatter_Elsewhere\YouTube\ThumbnailException;

class ThumbnailServiceTest extends TestCase {

	/** @var array<int, array{method: string, url: string, headers: array<string, string>, body: string}> */
	private array $requests = [];

	/** @var array{status: int, headers: array<string, string>, body: string}|Closure */
	private $response = [ 'status' => 200, 'headers' => [], 'body' => '{}' ];

	private function service( string|false $rendered = 'JPEGDATA' ): ThumbnailService {
		$tokens = new AccessTokenProvider(
			new YouTubeSettings( static fn(): array => [ 'refresh_token' => 'r', 'status' => 'connected' ], static function ( array $v ): void {} ),
			new OAuthClient( 'id', 'secret', 'https://example.org/cb', static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => 'tok', 'expires_in' => 3600 ] ] ),
			static fn(): ?array => null,
			static function ( array $v, int $ttl ): void {},
			static function (): void {},
			static fn(): int => 0
		);

		return new ThumbnailService(
			new ThumbnailClient(
				function ( string $method, string $url, array $headers, string $body ): array {
					$this->requests[] = compact( 'method', 'url', 'headers', 'body' );

					return $this->response instanceof Closure ? ( $this->response )() : $this->response;
				},
				$tokens
			),
			new ThumbnailPreparer( static fn(): string|false => $rendered )
		);
	}

	public function test_sends_the_prepared_image(): void {
		$this->service()->send( 'abcDEF_-123', '/a.jpg' );

		$request = $this->requests[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?uploadType=media&videoId=abcDEF_-123', $request['url'] );
		$this->assertSame( 'Bearer tok', $request['headers']['Authorization'] );
		$this->assertSame( 'image/jpeg', $request['headers']['Content-Type'] );
		$this->assertSame( 'JPEGDATA', $request['body'] );
	}

	public function test_an_image_that_cannot_be_prepared_is_reported_without_any_request(): void {
		try {
			$this->service( false )->send( 'abcDEF_-123', '/a.jpg' );
			$this->fail( 'Expected a ThumbnailException.' );
		} catch ( ThumbnailException $e ) {
			$this->assertSame( [], $this->requests );
			$this->assertNotSame( '', $e->getMessage() );
		}
	}

	public function test_a_forbidden_answer_mentions_verified_channels(): void {
		$this->response = [ 'status' => 403, 'headers' => [], 'body' => '{"error":{"message":"No permission.","errors":[{"reason":"forbidden"}]}}' ];

		try {
			$this->service()->send( 'abcDEF_-123', '/a.jpg' );
			$this->fail( 'Expected a ThumbnailException.' );
		} catch ( ThumbnailException $e ) {
			$this->assertStringContainsString( 'No permission.', $e->getMessage() );
			$this->assertStringContainsString( 'verified', $e->getMessage() );
		}
	}

	public function test_other_errors_do_not_mention_verification(): void {
		$this->response = [ 'status' => 400, 'headers' => [], 'body' => '{"error":{"message":"Bad image.","errors":[{"reason":"mediaBodyRequired"}]}}' ];

		try {
			$this->service()->send( 'abcDEF_-123', '/a.jpg' );
			$this->fail( 'Expected a ThumbnailException.' );
		} catch ( ThumbnailException $e ) {
			$this->assertStringContainsString( 'Bad image.', $e->getMessage() );
			$this->assertStringNotContainsString( 'verified', $e->getMessage() );
		}
	}

	public function test_transport_errors_are_reported(): void {
		$this->response = static function (): array {
			throw new RuntimeException( 'timeout' );
		};

		$this->expectException( ThumbnailException::class );
		$this->expectExceptionMessage( 'timeout' );

		$this->service()->send( 'abcDEF_-123', '/a.jpg' );
	}
}
