<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;
use WP_Scatter_Elsewhere\YouTube\AccessTokenProvider;
use WP_Scatter_Elsewhere\YouTube\OAuthClient;
use WP_Scatter_Elsewhere\YouTube\ReauthorizationRequiredException;
use WP_Scatter_Elsewhere\YouTube\VideoUpdateException;
use WP_Scatter_Elsewhere\YouTube\VideoUpdater;

class VideoUpdaterTest extends TestCase {

	/** @var array<int, array{method: string, url: string, headers: array<string, string>, body: string}> */
	private array $requests = [];

	/** @var array<int, array{status: int, headers: array<string, string>, body: string}|Closure> */
	private array $responses = [];

	private function updater( array $settings = [ 'refresh_token' => 'r', 'status' => 'connected' ] ): VideoUpdater {
		$tokens = new AccessTokenProvider(
			new YouTubeSettings( static fn(): array => $settings, static function ( array $v ): void {} ),
			new OAuthClient( 'id', 'secret', 'https://example.org/cb', static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => 'tok', 'expires_in' => 3600 ] ] ),
			static fn(): ?array => null,
			static function ( array $v, int $ttl ): void {},
			static function (): void {},
			static fn(): int => 0
		);

		return new VideoUpdater(
			function ( string $method, string $url, array $headers, string $body ): array {
				$this->requests[] = compact( 'method', 'url', 'headers', 'body' );
				$response         = array_shift( $this->responses );
				if ( $response instanceof Closure ) {
					return $response();
				}

				return $response ?? [ 'status' => 500, 'headers' => [], 'body' => '' ];
			},
			$tokens
		);
	}

	private static function video( array $overrides = [] ): array {
		return [
			'status' => 200,
			'headers' => [],
			'body'   => (string) json_encode(
				[
					'items' => [
						array_replace_recursive(
							[
								'id'               => 'abcDEF_-123',
								'snippet'          => [
									'publishedAt'  => '2026-10-05T20:00:00Z',
									'channelId'    => 'UC1',
									'title'        => 'Old title',
									'description'  => 'Old description',
									'thumbnails'   => [ 'default' => [ 'url' => 'https://i.ytimg.com/x.jpg' ] ],
									'tags'         => [ 'a', 'b' ],
									'categoryId'   => '19',
									'liveBroadcastContent' => 'none',
									'localized'    => [ 'title' => 'Old title' ],
								],
								'status'           => [
									'uploadStatus'            => 'processed',
									'privacyStatus'           => 'private',
									'license'                 => 'youtube',
									'embeddable'              => true,
									'publicStatsViewable'     => false,
									'madeForKids'             => false,
									'selfDeclaredMadeForKids' => false,
								],
								'recordingDetails' => [ 'location' => [ 'latitude' => 45.1, 'longitude' => 5.7 ] ],
							],
							$overrides
						),
					],
				]
			),
		];
	}

	private function putBody(): array {
		return json_decode( $this->requests[1]['body'], true );
	}

	public function test_reads_the_video_then_sends_it_back_with_only_the_requested_changes(): void {
		$this->responses = [ self::video(), [ 'status' => 200, 'headers' => [], 'body' => '{}' ] ];

		$this->updater()->update( 'abcDEF_-123', [ 'language' => 'fr', 'license' => 'creativeCommon', 'recording_date' => '2026-10-05T12:00:00Z' ] );

		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'GET', $this->requests[0]['method'] );
		$this->assertSame( 'https://www.googleapis.com/youtube/v3/videos?part=snippet,status,recordingDetails&id=abcDEF_-123', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer tok', $this->requests[0]['headers']['Authorization'] );
		$this->assertSame( 'PUT', $this->requests[1]['method'] );
		$this->assertSame( 'https://www.googleapis.com/youtube/v3/videos?part=snippet,status,recordingDetails', $this->requests[1]['url'] );

		$this->assertSame(
			[
				'id'               => 'abcDEF_-123',
				'snippet'          => [
					'title'                => 'Old title',
					'description'          => 'Old description',
					'tags'                 => [ 'a', 'b' ],
					'categoryId'           => '19',
					'defaultLanguage'      => 'fr',
					'defaultAudioLanguage' => 'fr',
				],
				'status'           => [
					'privacyStatus'           => 'private',
					'license'                 => 'creativeCommon',
					'embeddable'              => true,
					'publicStatsViewable'     => false,
					'selfDeclaredMadeForKids' => false,
				],
				'recordingDetails' => [ 'location' => [ 'latitude' => 45.1, 'longitude' => 5.7 ], 'recordingDate' => '2026-10-05T12:00:00Z' ],
			],
			$this->putBody()
		);
	}

	public function test_read_only_properties_are_not_sent_back(): void {
		$this->responses = [ self::video(), [ 'status' => 200, 'headers' => [], 'body' => '{}' ] ];

		$this->updater()->update( 'abcDEF_-123', [ 'license' => 'creativeCommon' ] );

		$body = $this->putBody();
		foreach ( [ 'publishedAt', 'channelId', 'thumbnails', 'liveBroadcastContent', 'localized' ] as $readOnly ) {
			$this->assertArrayNotHasKey( $readOnly, $body['snippet'] );
		}
		foreach ( [ 'uploadStatus', 'madeForKids' ] as $readOnly ) {
			$this->assertArrayNotHasKey( $readOnly, $body['status'] );
		}
		$this->assertSame( 'Old title', $body['snippet']['title'] );
		$this->assertSame( 'private', $body['status']['privacyStatus'] );
	}

	public function test_title_and_description_can_be_updated(): void {
		$this->responses = [ self::video(), [ 'status' => 200, 'headers' => [], 'body' => '{}' ] ];

		$this->updater()->update( 'abcDEF_-123', [ 'title' => 'Nouveau « titre »', 'description' => 'Nouvelle description' ] );

		$this->assertSame( 'Nouveau « titre »', $this->putBody()['snippet']['title'] );
		$this->assertStringContainsString( 'Nouveau « titre »', $this->requests[1]['body'] );
		$this->assertSame( 'Nouvelle description', $this->putBody()['snippet']['description'] );
		$this->assertSame( [ 'a', 'b' ], $this->putBody()['snippet']['tags'] );
	}

	public function test_a_video_without_recording_details_gets_them_only_when_asked(): void {
		$video = self::video();
		$data  = json_decode( $video['body'], true );
		unset( $data['items'][0]['recordingDetails'], $data['items'][0]['snippet']['description'] );
		$video['body'] = (string) json_encode( $data );

		$this->responses = [ $video, [ 'status' => 200, 'headers' => [], 'body' => '{}' ] ];
		$this->updater()->update( 'abcDEF_-123', [ 'language' => 'fr' ] );

		$this->assertSame( 'https://www.googleapis.com/youtube/v3/videos?part=snippet,status', $this->requests[1]['url'] );
		$this->assertArrayNotHasKey( 'recordingDetails', $this->putBody() );
		$this->assertSame( '', $this->putBody()['snippet']['description'] );
	}

	public function test_an_unknown_video_is_reported(): void {
		$this->responses = [ [ 'status' => 200, 'headers' => [], 'body' => '{"items":[]}' ] ];

		try {
			$this->updater()->update( 'abcDEF_-123', [ 'language' => 'fr' ] );
			$this->fail( 'Expected a VideoUpdateException.' );
		} catch ( VideoUpdateException $e ) {
			$this->assertStringContainsString( 'abcDEF_-123', $e->getMessage() );
			$this->assertCount( 1, $this->requests );
		}
	}

	public function test_api_and_transport_errors_are_reported(): void {
		$quota = (string) json_encode( [ 'error' => [ 'message' => 'Quota.', 'errors' => [ [ 'reason' => 'quotaExceeded' ] ] ] ] );

		$this->responses = [ [ 'status' => 403, 'headers' => [], 'body' => $quota ] ];
		try {
			$this->updater()->update( 'abcDEF_-123', [ 'language' => 'fr' ] );
			$this->fail( 'Expected a VideoUpdateException.' );
		} catch ( VideoUpdateException $e ) {
			$this->assertStringContainsString( 'quotaExceeded', $e->getMessage() );
		}

		$this->responses = [ self::video(), [ 'status' => 400, 'headers' => [], 'body' => '{"error":{"message":"Invalid language."}}' ] ];
		try {
			$this->updater()->update( 'abcDEF_-123', [ 'language' => 'xx' ] );
			$this->fail( 'Expected a VideoUpdateException.' );
		} catch ( VideoUpdateException $e ) {
			$this->assertStringContainsString( 'Invalid language.', $e->getMessage() );
		}

		$this->responses = [ static function (): array {
			throw new RuntimeException( 'timeout' );
		} ];
		$this->expectException( VideoUpdateException::class );
		$this->updater()->update( 'abcDEF_-123', [ 'language' => 'fr' ] );
	}

	public function test_unknown_fields_and_empty_values_are_refused_without_any_request(): void {
		foreach ( [ [ 'views' => '10' ], [ 'language' => '' ], [ 'keywords' => 'one' ], [ 'category' => [ '22' ] ] ] as $changes ) {
			try {
				$this->updater()->update( 'abcDEF_-123', $changes );
				$this->fail( 'Expected an InvalidArgumentException.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( [], $this->requests );
			}
		}
	}

	public function test_a_revoked_authorisation_is_reported_without_any_request(): void {
		$this->expectException( ReauthorizationRequiredException::class );

		try {
			$this->updater( [ 'status' => 'needs_reauth' ] )->update( 'abcDEF_-123', [ 'language' => 'fr' ] );
		} finally {
			$this->assertSame( [], $this->requests );
		}
	}

	public function test_keywords_are_added_to_the_existing_ones(): void {
		$this->responses = [ self::video(), [ 'status' => 200, 'headers' => [], 'body' => '{}' ] ];

		$dropped = $this->updater()->update( 'abcDEF_-123', [ 'keywords' => [ 'B', 'vanlife', 'Salers' ] ] );

		$this->assertSame( [], $dropped );
		$this->assertSame( [ 'a', 'b', 'vanlife', 'Salers' ], $this->putBody()['snippet']['tags'] );
		$this->assertSame( 'Old title', $this->putBody()['snippet']['title'] );
	}

	public function test_keywords_that_do_not_fit_are_returned_and_the_existing_ones_are_kept(): void {
		$video = self::video();
		$data  = json_decode( $video['body'], true );
		$data['items'][0]['snippet']['tags'] = [ str_repeat( 'x', 490 ) ];
		$video['body'] = (string) json_encode( $data );
		$this->responses = [ $video, [ 'status' => 200, 'headers' => [], 'body' => '{}' ] ];

		$dropped = $this->updater()->update( 'abcDEF_-123', [ 'keywords' => [ 'too long to add' ] ] );

		$this->assertSame( [ 'too long to add' ], $dropped );
		$this->assertSame( [ str_repeat( 'x', 490 ) ], $this->putBody()['snippet']['tags'] );
	}

	public function test_keywords_must_be_a_non_empty_list_and_other_fields_must_not_be_lists(): void {
		foreach ( [ [ 'keywords' => [] ], [ 'title' => [ 'x' ] ] ] as $changes ) {
			try {
				$this->updater()->update( 'abcDEF_-123', $changes );
				$this->fail( 'Expected an InvalidArgumentException.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( [], $this->requests );
			}
		}
	}

	public function test_updates_the_category_the_options_and_the_privacy(): void {
		$this->responses = [ self::video(), [ 'status' => 200, 'headers' => [], 'body' => '{}' ] ];

		$this->updater()->update( 'abcDEF_-123', [ 'category' => '27', 'embeddable' => false, 'public_stats' => true, 'made_for_kids' => true, 'privacy' => 'unlisted' ] );

		$body = $this->putBody();
		$this->assertSame( '27', $body['snippet']['categoryId'] );
		$this->assertFalse( $body['status']['embeddable'] );
		$this->assertTrue( $body['status']['publicStatsViewable'] );
		$this->assertTrue( $body['status']['selfDeclaredMadeForKids'] );
		$this->assertSame( 'unlisted', $body['status']['privacyStatus'] );
	}

	public function test_takes_keywords_out_and_puts_new_ones_in(): void {
		$this->responses = [ self::video(), [ 'status' => 200, 'headers' => [], 'body' => '{}' ] ];

		$this->updater()->update( 'abcDEF_-123', [ 'keywords_remove' => [ 'A' ], 'keywords' => [ 'c' ] ] );

		$this->assertSame( [ 'b', 'c' ], $this->putBody()['snippet']['tags'] );
	}

	public function test_a_snapshot_is_the_video_as_read(): void {
		$this->responses = [ self::video() ];

		$this->assertSame( 'Old title', $this->updater()->snapshot( 'abcDEF_-123' )['snippet']['title'] );
		$this->assertCount( 1, $this->requests );
	}
}
