<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Publication\LinkException;
use WP_Scatter_Elsewhere\Publication\LinkIndex;
use WP_Scatter_Elsewhere\Publication\LinkService;
use WP_Scatter_Elsewhere\Publication\Publication;
use WP_Scatter_Elsewhere\Publication\PublicationStore;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;
use WP_Scatter_Elsewhere\YouTube\AccessTokenProvider;
use WP_Scatter_Elsewhere\YouTube\OAuthClient;
use WP_Scatter_Elsewhere\YouTube\VideoInspector;

class LinkServiceTest extends TestCase {

	/** @var array<int, mixed> */
	private array $meta = [];

	/** @var array{status: int, headers: array<string, string>, body: string} */
	private array $response;

	/** @var array<int, array{int, string}> */
	private array $unlinked = [];

	/** @var array{post_id: int, video_id: string}|null */
	private ?array $linkedTo = null;

	private string $channel = 'UC1';

	private int $requests = 0;

	protected function setUp(): void {
		$this->response = $this->video();
	}

	private function video( string $channel = 'UC1', string $privacy = 'private', string $title = 'Grenoble ⇾ Salers' ): array {
		return [
			'status'  => 200,
			'headers' => [],
			'body'    => (string) json_encode( [ 'items' => [ [ 'id' => '9FzZpnEKL-s', 'snippet' => [ 'title' => $title, 'publishedAt' => '2026-10-05T20:00:00Z', 'channelId' => $channel ], 'status' => [ 'privacyStatus' => $privacy ] ] ] ] ),
		];
	}

	private function service(): LinkService {
		$tokens = new AccessTokenProvider(
			new YouTubeSettings( static fn(): array => [ 'refresh_token' => 'r', 'status' => 'connected' ], static function ( array $v ): void {} ),
			new OAuthClient( 'id', 'secret', 'https://example.org/cb', static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => 'tok', 'expires_in' => 3600 ] ] ),
			static fn(): ?array => null,
			static function ( array $v, int $ttl ): void {},
			static function (): void {},
			static fn(): int => 0
		);
		$store  = new PublicationStore(
			fn( int $postId ): mixed => $this->meta[ $postId ] ?? '',
			function ( int $postId, array $value ): void {
				$this->meta[ $postId ] = $value;
			}
		);

		return new LinkService(
			new VideoInspector(
				function (): array {
					++$this->requests;

					return $this->response;
				},
				$tokens
			),
			$store,
			function ( int $postId, string $videoId ): bool {
				$this->unlinked[] = [ $postId, $videoId ];

				return true;
			},
			fn( string $youtubeId ): ?array => '9FzZpnEKL-s' === $youtubeId ? $this->linkedTo : null,
			fn(): string => $this->channel,
			static fn(): int => 1760000000
		);
	}

	public function test_the_preview_shows_what_youtube_says_about_the_video(): void {
		$preview = $this->service()->preview( 7, 'v1', 'https://youtu.be/9FzZpnEKL-s?t=3' );

		$this->assertSame( '9FzZpnEKL-s', $preview->youtubeId );
		$this->assertSame( 'Grenoble ⇾ Salers', $preview->title );
		$this->assertSame( 'private', $preview->privacy );
		$this->assertSame( '2026-10-05T20:00:00Z', $preview->publishedAt );
		$this->assertTrue( $preview->channelChecked );
		$this->assertNull( $preview->linkedTo );
		$this->assertSame( [], $this->meta );
	}

	public function test_an_invalid_address_is_refused_without_any_request(): void {
		try {
			$this->service()->preview( 7, 'v1', 'https://example.org/video' );
			$this->fail( 'Expected a LinkException.' );
		} catch ( LinkException $e ) {
			$this->assertSame( 'invalid_address', $e->errorCode() );
			$this->assertSame( 0, $this->requests );
		}
	}

	public function test_a_video_of_another_channel_is_refused(): void {
		$this->response = $this->video( 'UC-other' );

		try {
			$this->service()->link( 7, 'v1', '9FzZpnEKL-s' );
			$this->fail( 'Expected a LinkException.' );
		} catch ( LinkException $e ) {
			$this->assertSame( 'other_channel', $e->errorCode() );
			$this->assertSame( [], $this->meta );
		}
	}

	public function test_the_channel_check_is_skipped_when_the_connected_channel_is_unknown(): void {
		$this->channel  = '';
		$this->response = $this->video( 'UC-other' );

		$preview = $this->service()->preview( 7, 'v1', '9FzZpnEKL-s' );

		$this->assertFalse( $preview->channelChecked );
	}

	public function test_a_video_that_cannot_be_read_is_reported(): void {
		$this->response = [ 'status' => 200, 'headers' => [], 'body' => '{"items":[]}' ];

		try {
			$this->service()->preview( 7, 'v1', '9FzZpnEKL-s' );
			$this->fail( 'Expected a LinkException.' );
		} catch ( LinkException $e ) {
			$this->assertSame( 'unreadable', $e->errorCode() );
			$this->assertStringContainsString( '9FzZpnEKL-s', $e->getMessage() );
		}
	}

	public function test_linking_records_the_privacy_read_from_youtube_and_the_check_time(): void {
		$publication = $this->service()->link( 7, 'v1', 'https://www.youtube.com/watch?v=9FzZpnEKL-s' );

		$this->assertSame( '9FzZpnEKL-s', $publication->youtubeId );
		$this->assertSame( 'private', $publication->privacy );
		$this->assertNull( $publication->jobId );
		$this->assertSame( 1760000000, $publication->checkedAt );
		$this->assertSame( strtotime( '2026-10-05T20:00:00Z' ), $publication->publishedAt );
		$this->assertSame(
			[ 'v1' => [ 'youtube_id' => '9FzZpnEKL-s', 'privacy' => 'private', 'published_at' => strtotime( '2026-10-05T20:00:00Z' ), 'job_id' => null, 'checked_at' => 1760000000 ] ],
			$this->meta[7]
		);
		$this->assertSame( [], $this->unlinked );
	}

	public function test_a_video_linked_elsewhere_needs_a_confirmation(): void {
		$this->linkedTo = [ 'post_id' => 3, 'video_id' => 'vold' ];

		$preview = $this->service()->preview( 7, 'v1', '9FzZpnEKL-s' );
		$this->assertSame( [ 'post_id' => 3, 'video_id' => 'vold' ], $preview->linkedTo );

		try {
			$this->service()->link( 7, 'v1', '9FzZpnEKL-s' );
			$this->fail( 'Expected a LinkException.' );
		} catch ( LinkException $e ) {
			$this->assertSame( 'already_linked', $e->errorCode() );
			$this->assertSame( [ 'post_id' => 3, 'video_id' => 'vold' ], $e->linkedTo() );
			$this->assertSame( [], $this->meta );
			$this->assertSame( [], $this->unlinked );
		}
	}

	public function test_a_confirmed_move_unlinks_the_other_video_first(): void {
		$this->linkedTo = [ 'post_id' => 3, 'video_id' => 'vold' ];

		$this->service()->link( 7, 'v1', '9FzZpnEKL-s', true );

		$this->assertSame( [ [ 3, 'vold' ] ], $this->unlinked );
		$this->assertSame( '9FzZpnEKL-s', $this->meta[7]['v1']['youtube_id'] );
	}

	public function test_relinking_the_same_video_is_not_a_conflict(): void {
		$this->linkedTo = [ 'post_id' => 7, 'video_id' => 'v1' ];

		$preview = $this->service()->preview( 7, 'v1', '9FzZpnEKL-s' );

		$this->assertNull( $preview->linkedTo );
		$this->service()->link( 7, 'v1', '9FzZpnEKL-s' );
		$this->assertSame( [], $this->unlinked );
	}

	public function test_linking_without_a_check_asks_youtube_nothing_and_moves_the_link(): void {
		$this->linkedTo = [ 'post_id' => 3, 'video_id' => 'vold' ];

		$publication = $this->service()->linkUnchecked( 7, 'v1', 'https://youtu.be/9FzZpnEKL-s', 'public' );

		$this->assertSame( 0, $this->requests );
		$this->assertSame( 'public', $publication->privacy );
		$this->assertNull( $publication->checkedAt );
		$this->assertSame( [ [ 3, 'vold' ] ], $this->unlinked );
		$this->assertSame( '9FzZpnEKL-s', $this->meta[7]['v1']['youtube_id'] );
	}

	public function test_linking_without_a_check_still_validates_the_address_and_the_privacy(): void {
		foreach ( [ [ 'nonsense', null, 'invalid_address' ], [ '9FzZpnEKL-s', 'everyone', 'invalid_privacy' ] ] as [ $address, $privacy, $code ] ) {
			try {
				$this->service()->linkUnchecked( 7, 'v1', $address, $privacy );
				$this->fail( 'Expected a LinkException.' );
			} catch ( LinkException $e ) {
				$this->assertSame( $code, $e->errorCode() );
			}
		}

		$this->assertSame( [], $this->meta );
	}

	public function test_unlinking_goes_through_the_unlink_operation(): void {
		$this->assertTrue( $this->service()->unlink( 7, 'v1' ) );
		$this->assertSame( [ [ 7, 'v1' ] ], $this->unlinked );
	}

	public function test_the_index_finds_where_a_youtube_video_is_linked(): void {
		$index = new LinkIndex(
			static function (): iterable {
				yield [ 'post_id' => 3, 'video_id' => 'va', 'youtube_id' => 'aaaaaaaaaaa' ];
				yield [ 'post_id' => 4, 'video_id' => 'vb', 'youtube_id' => 'bbbbbbbbbbb' ];
			}
		);

		$this->assertSame( [ 'post_id' => 4, 'video_id' => 'vb' ], $index->find( 'bbbbbbbbbbb' ) );
		$this->assertNull( $index->find( 'ccccccccccc' ) );
	}
}
