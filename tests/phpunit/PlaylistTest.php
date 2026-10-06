<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Playlists\PlaylistService;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;
use WP_Scatter_Elsewhere\YouTube\AccessTokenProvider;
use WP_Scatter_Elsewhere\YouTube\OAuthClient;
use WP_Scatter_Elsewhere\YouTube\PlaylistClient;
use WP_Scatter_Elsewhere\YouTube\PlaylistException;

class PlaylistTest extends TestCase {

	/** @var array<int, array{method: string, url: string, headers: array<string, string>, body: string}> */
	private array $requests = [];

	/** @var array<int, array{status: int, headers: array<string, string>, body: string}> */
	private array $responses = [];

	private function client(): PlaylistClient {
		$tokens = new AccessTokenProvider(
			new YouTubeSettings( static fn(): array => [ 'refresh_token' => 'r', 'status' => 'connected' ], static function ( array $v ): void {} ),
			new OAuthClient( 'id', 'secret', 'https://example.org/cb', static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => 'tok', 'expires_in' => 3600 ] ] ),
			static fn(): ?array => null,
			static function ( array $v, int $ttl ): void {},
			static function (): void {},
			static fn(): int => 0
		);

		return new PlaylistClient(
			function ( string $method, string $url, array $headers, string $body ): array {
				$this->requests[] = compact( 'method', 'url', 'headers', 'body' );
				$response         = array_shift( $this->responses ) ?? [ 'status' => 200, 'headers' => [], 'body' => '{"items":[]}' ];

				return $response;
			},
			$tokens
		);
	}

	private static function json( array $data, int $status = 200 ): array {
		return [ 'status' => $status, 'headers' => [], 'body' => (string) json_encode( $data ) ];
	}

	public function test_lists_the_playlists_of_the_channel_across_pages(): void {
		$this->responses = [
			self::json( [ 'items' => [ [ 'id' => 'PL1', 'snippet' => [ 'title' => 'Vanlife' ] ] ], 'nextPageToken' => 'NEXT' ] ),
			self::json( [ 'items' => [ [ 'id' => 'PL2', 'snippet' => [ 'title' => 'Vélo' ] ], [ 'id' => 'PL3' ] ] ] ),
		];

		$playlists = $this->client()->playlists();

		$this->assertSame( [ 'PL1' => 'Vanlife', 'PL2' => 'Vélo', 'PL3' => 'PL3' ], $playlists );
		$this->assertSame( 'https://www.googleapis.com/youtube/v3/playlists?part=snippet&mine=true&maxResults=50', $this->requests[0]['url'] );
		$this->assertSame( 'https://www.googleapis.com/youtube/v3/playlists?part=snippet&mine=true&maxResults=50&pageToken=NEXT', $this->requests[1]['url'] );
		$this->assertSame( 'Bearer tok', $this->requests[0]['headers']['Authorization'] );
	}

	public function test_checks_whether_a_playlist_contains_a_video(): void {
		$this->responses = [ self::json( [ 'items' => [ [ 'id' => 'item' ] ] ] ), self::json( [ 'items' => [] ] ) ];
		$client          = $this->client();

		$this->assertTrue( $client->contains( 'PLaaaaaaaaaaa', 'vid' ) );
		$this->assertFalse( $client->contains( 'PLaaaaaaaaaaa', 'vid' ) );
		$this->assertSame( 'https://www.googleapis.com/youtube/v3/playlistItems?part=id&maxResults=1&playlistId=PLaaaaaaaaaaa&videoId=vid', $this->requests[0]['url'] );
	}

	public function test_adds_a_video_to_a_playlist(): void {
		$this->responses = [ self::json( [] ) ];

		$this->client()->add( 'PLaaaaaaaaaaa', 'vid' );

		$request = $this->requests[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://www.googleapis.com/youtube/v3/playlistItems?part=snippet', $request['url'] );
		$this->assertSame( 'application/json; charset=UTF-8', $request['headers']['Content-Type'] );
		$this->assertSame(
			[ 'snippet' => [ 'playlistId' => 'PLaaaaaaaaaaa', 'resourceId' => [ 'kind' => 'youtube#video', 'videoId' => 'vid' ] ] ],
			json_decode( $request['body'], true )
		);
	}

	public function test_errors_are_raised_with_the_quota_flag(): void {
		$quota = [ 'error' => [ 'message' => 'Quota.', 'errors' => [ [ 'reason' => 'quotaExceeded' ] ] ] ];

		$this->responses = [ self::json( $quota, 403 ), self::json( [ 'error' => [ 'message' => 'Nope.' ] ], 404 ) ];
		$client          = $this->client();

		try {
			$client->playlists();
			$this->fail( 'Expected a PlaylistException.' );
		} catch ( PlaylistException $e ) {
			$this->assertTrue( $e->isQuota() );
		}

		try {
			$client->add( 'PLaaaaaaaaaaa', 'vid' );
			$this->fail( 'Expected a PlaylistException.' );
		} catch ( PlaylistException $e ) {
			$this->assertFalse( $e->isQuota() );
			$this->assertStringContainsString( 'Nope.', $e->getMessage() );
		}
	}

	public function test_the_service_skips_playlists_that_already_have_the_video(): void {
		$this->responses = [ self::json( [ 'items' => [ [ 'id' => 'x' ] ] ] ), self::json( [ 'items' => [] ] ), self::json( [] ) ];

		$result = ( new PlaylistService( $this->client() ) )->addTo( 'vid', [ 'PLaaaaaaaaaaa', 'PLbbbbbbbbbbb', 'PLaaaaaaaaaaa' ] );

		$this->assertSame( [ 'PLbbbbbbbbbbb' ], $result->added );
		$this->assertSame( [ 'PLaaaaaaaaaaa' ], $result->skipped );
		$this->assertFalse( $result->hasErrors() );
		$this->assertSame( [ 'GET', 'GET', 'POST' ], array_column( $this->requests, 'method' ) );
	}

	public function test_a_problem_with_one_playlist_does_not_stop_the_others_but_a_quota_error_does(): void {
		$quota           = [ 'error' => [ 'errors' => [ [ 'reason' => 'quotaExceeded' ] ] ] ];
		$this->responses = [ self::json( [ 'error' => [ 'message' => 'Gone.' ] ], 404 ), self::json( [ 'items' => [] ] ), self::json( [] ) ];

		$result = ( new PlaylistService( $this->client() ) )->addTo( 'vid', [ 'PLaaaaaaaaaaa', 'PLbbbbbbbbbbb' ] );

		$this->assertSame( [ 'PLbbbbbbbbbbb' ], $result->added );
		$this->assertStringContainsString( 'PLaaaaaaaaaaa: ', $result->errorSummary() );

		$this->requests  = [];
		$this->responses = [ self::json( $quota, 403 ) ];

		$result = ( new PlaylistService( $this->client() ) )->addTo( 'vid', [ 'PLaaaaaaaaaaa', 'PLbbbbbbbbbbb' ] );

		$this->assertSame( [], $result->added );
		$this->assertCount( 1, $this->requests );
	}
}
