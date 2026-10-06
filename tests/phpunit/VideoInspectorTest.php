<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Publication\Publication;
use WP_Scatter_Elsewhere\Publication\PublicationRefresher;
use WP_Scatter_Elsewhere\Publication\PublicationStore;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;
use WP_Scatter_Elsewhere\YouTube\AccessTokenProvider;
use WP_Scatter_Elsewhere\YouTube\OAuthClient;
use WP_Scatter_Elsewhere\YouTube\VideoInspector;
use WP_Scatter_Elsewhere\YouTube\VideoUpdateException;

class VideoInspectorTest extends TestCase {

	/** @var array<int, array{method: string, url: string, headers: array<string, string>}> */
	private array $requests = [];

	/** @var array{status: int, headers: array<string, string>, body: string}|Closure */
	private $response;

	/** @var array<int, mixed> */
	private array $meta = [];

	private function inspector(): VideoInspector {
		$tokens = new AccessTokenProvider(
			new YouTubeSettings( static fn(): array => [ 'refresh_token' => 'r', 'status' => 'connected' ], static function ( array $v ): void {} ),
			new OAuthClient( 'id', 'secret', 'https://example.org/cb', static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => 'tok', 'expires_in' => 3600 ] ] ),
			static fn(): ?array => null,
			static function ( array $v, int $ttl ): void {},
			static function (): void {},
			static fn(): int => 0
		);

		return new VideoInspector(
			function ( string $method, string $url, array $headers, string $body ): array {
				$this->requests[] = compact( 'method', 'url', 'headers' );

				return $this->response instanceof Closure ? ( $this->response )() : $this->response;
			},
			$tokens
		);
	}

	private static function statusBody( string $privacy ): array {
		return [ 'status' => 200, 'headers' => [], 'body' => (string) json_encode( [ 'items' => [ [ 'id' => 'abc', 'status' => [ 'privacyStatus' => $privacy, 'license' => 'youtube' ] ] ] ] ) ];
	}

	public function test_reads_the_privacy_of_a_video(): void {
		$this->response = self::statusBody( 'unlisted' );

		$this->assertSame( 'unlisted', $this->inspector()->privacy( 'abcDEF_-123' ) );
		$this->assertSame( 'GET', $this->requests[0]['method'] );
		$this->assertSame( 'https://www.googleapis.com/youtube/v3/videos?part=status&id=abcDEF_-123', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer tok', $this->requests[0]['headers']['Authorization'] );
	}

	public function test_a_video_that_is_not_there_is_reported(): void {
		$this->response = [ 'status' => 200, 'headers' => [], 'body' => '{"items":[]}' ];

		$this->expectException( VideoUpdateException::class );
		$this->expectExceptionMessage( 'abcDEF_-123' );

		$this->inspector()->privacy( 'abcDEF_-123' );
	}

	public function test_api_and_transport_errors_are_reported(): void {
		$this->response = [ 'status' => 403, 'headers' => [], 'body' => '{"error":{"message":"Quota.","errors":[{"reason":"quotaExceeded"}]}}' ];
		try {
			$this->inspector()->privacy( 'abcDEF_-123' );
			$this->fail( 'Expected a VideoUpdateException.' );
		} catch ( VideoUpdateException $e ) {
			$this->assertStringContainsString( 'quotaExceeded', $e->getMessage() );
		}

		$this->response = static function (): array {
			throw new RuntimeException( 'timeout' );
		};
		$this->expectException( VideoUpdateException::class );
		$this->expectExceptionMessage( 'timeout' );
		$this->inspector()->privacy( 'abcDEF_-123' );
	}

	public function test_the_refresher_records_the_real_privacy_and_the_time_of_the_check(): void {
		$this->response = self::statusBody( 'public' );
		$store          = new PublicationStore(
			fn( int $postId ): mixed => $this->meta[ $postId ] ?? '',
			function ( int $postId, array $value ): void {
				$this->meta[ $postId ] = $value;
			}
		);
		$old            = new Publication( 'v1', 'abcDEF_-123', 'private', 1000, 'j1' );

		$new = ( new PublicationRefresher( $this->inspector(), $store ) )->refresh( 7, $old, 5000 );

		$this->assertSame( 'public', $new->privacy );
		$this->assertSame( 5000, $new->checkedAt );
		$this->assertSame( [ 1000, 'j1', 'abcDEF_-123' ], [ $new->publishedAt, $new->jobId, $new->youtubeId ] );
		$this->assertSame( [ 'v1' => [ 'youtube_id' => 'abcDEF_-123', 'privacy' => 'public', 'published_at' => 1000, 'job_id' => 'j1', 'checked_at' => 5000 ] ], $this->meta[7] );
	}

	public function test_the_refresher_keeps_the_record_when_youtube_cannot_be_read(): void {
		$this->response = [ 'status' => 500, 'headers' => [], 'body' => '' ];
		$store          = new PublicationStore(
			fn( int $postId ): mixed => $this->meta[ $postId ] ?? '',
			function ( int $postId, array $value ): void {
				$this->meta[ $postId ] = $value;
			}
		);

		try {
			( new PublicationRefresher( $this->inspector(), $store ) )->refresh( 7, new Publication( 'v1', 'abcDEF_-123', 'private', 1000, null ), 5000 );
			$this->fail( 'Expected a VideoUpdateException.' );
		} catch ( VideoUpdateException $e ) {
			$this->assertSame( [], $this->meta );
		}
	}
}
