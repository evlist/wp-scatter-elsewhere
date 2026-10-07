<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Publication\ChannelCatalog;
use WP_Scatter_Elsewhere\Publication\LinkIndex;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;
use WP_Scatter_Elsewhere\Text\Folding;
use WP_Scatter_Elsewhere\YouTube\AccessTokenProvider;
use WP_Scatter_Elsewhere\YouTube\CatalogVideo;
use WP_Scatter_Elsewhere\YouTube\ChannelCatalogException;
use WP_Scatter_Elsewhere\YouTube\ChannelVideoReader;
use WP_Scatter_Elsewhere\YouTube\OAuthClient;

class ChannelCatalogTest extends TestCase {

	/** @var string[] */
	private array $urls = [];

	/** @var array<int, array{status: int, headers: array<string, string>, body: string}> */
	private array $responses = [];

	private mixed $cache = false;

	private int $reads = 0;

	private function reader(): ChannelVideoReader {
		$tokens = new AccessTokenProvider(
			new YouTubeSettings( static fn(): array => [ 'refresh_token' => 'r', 'status' => 'connected' ], static function ( array $v ): void {} ),
			new OAuthClient( 'id', 'secret', 'https://example.org/cb', static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => 'tok', 'expires_in' => 3600 ] ] ),
			static fn(): ?array => null,
			static function ( array $v, int $ttl ): void {},
			static function (): void {},
			static fn(): int => 0
		);

		return new ChannelVideoReader(
			function ( string $method, string $url, array $headers, string $body ): array {
				$this->urls[] = $url;
				return array_shift( $this->responses ) ?? [ 'status' => 200, 'headers' => [], 'body' => '{}' ];
			},
			$tokens
		);
	}

	private static function json( array $data, int $status = 200 ): array {
		return [ 'status' => $status, 'headers' => [], 'body' => (string) json_encode( $data ) ];
	}

	private static function channel(): array {
		return self::json( [ 'items' => [ [ 'contentDetails' => [ 'relatedPlaylists' => [ 'uploads' => 'UU1' ] ] ] ] ] );
	}

	private static function items( array $ids, ?string $next = null ): array {
		$data = [ 'items' => array_map( static fn( string $id ): array => [ 'contentDetails' => [ 'videoId' => $id ] ], $ids ) ];
		if ( null !== $next ) {
			$data['nextPageToken'] = $next;
		}
		return self::json( $data );
	}

	private static function videos( array $ids ): array {
		return self::json( [ 'items' => array_map( static fn( string $id ): array => [
			'id'               => $id,
			'snippet'          => [ 'title' => 'Titre ' . $id, 'publishedAt' => '2026-10-05T20:00:00Z', 'description' => 'D', 'thumbnails' => [ 'default' => [ 'url' => 'https://i/d.jpg' ], 'medium' => [ 'url' => 'https://i/m.jpg' ] ] ],
			'status'           => [ 'privacyStatus' => 'private' ],
			'recordingDetails' => [ 'recordingDate' => '2026-10-04T00:00:00Z' ],
		], $ids ) ] );
	}

	public function test_reads_uploads_in_channel_order(): void {
		$this->responses = [ self::channel(), self::items( [ 'a', 'b' ] ), self::videos( [ 'b', 'a' ] ) ];

		$videos = $this->reader()->read( 500 );

		$this->assertSame( [ 'a', 'b' ], array_map( static fn( $v ) => $v->id, $videos ) );
		$this->assertSame( 'https://i/m.jpg', $videos[0]->thumbnail );
		$this->assertSame( 'private', $videos[0]->privacy );
		$this->assertSame( '2026-10-04T00:00:00Z', $videos[0]->recordingDate );
		$this->assertStringContainsString( 'playlistId=UU1', $this->urls[1] );
	}

	public function test_pages_and_batches_of_fifty_and_stops_at_the_limit(): void {
		$first  = array_map( static fn( int $i ): string => 'v' . $i, range( 1, 50 ) );
		$second = array_map( static fn( int $i ): string => 'v' . $i, range( 51, 100 ) );
		$this->responses = [ self::channel(), self::items( $first, 'P2' ), self::items( $second, 'P3' ), self::videos( $first ), self::videos( array_slice( $second, 0, 20 ) ) ];

		$videos = $this->reader()->read( 70 );

		$this->assertCount( 70, $videos );
		$this->assertCount( 5, $this->urls, 'No third page is requested once the limit is reached.' );
		$this->assertStringContainsString( 'pageToken=P2', $this->urls[2] );
	}

	public function test_api_errors_become_catalog_exceptions(): void {
		$this->responses = [ self::json( [ 'error' => [ 'message' => 'nope' ] ], 403 ) ];
		$this->expectException( ChannelCatalogException::class );
		$this->reader()->read( 10 );
	}

	public function test_missing_uploads_playlist_is_an_error(): void {
		$this->responses = [ self::json( [ 'items' => [] ] ) ];
		$this->expectException( ChannelCatalogException::class );
		$this->reader()->read( 10 );
	}

	private function video( string $id, string $title ): CatalogVideo {
		return new CatalogVideo( $id, $title, '2026-10-05T20:00:00Z', 'public', '', null, null );
	}

	private function catalog(): ChannelCatalog {
		$clock = static fn(): int => 1000;
		return new ChannelCatalog(
			function ( int $limit ): array {
				++$this->reads;
				return [ $this->video( 'a', 'Grenoble ⇾ Salers' ), $this->video( 'b', 'Été à Lyon' ), $this->video( 'c', 'Vélo' ) ];
			},
			fn(): mixed => $this->cache,
			function ( array $value, int $ttl ): void {
				$this->cache = $value;
				$this->assertSame( ChannelCatalog::TTL, $ttl );
			},
			$clock,
			new LinkIndex( static fn(): iterable => [ [ 'post_id' => 7, 'video_id' => 'v1', 'youtube_id' => 'a' ] ] )
		);
	}

	public function test_caches_the_list_and_refreshes_on_demand(): void {
		$catalog = $this->catalog();
		$catalog->list();
		$result = $catalog->list();
		$this->assertSame( 1, $this->reads );
		$this->assertSame( 1000, $result['fetched_at'] );
		$this->assertCount( 3, $result['videos'] );

		$catalog->list( true );
		$this->assertSame( 2, $this->reads );
	}

	public function test_marks_linked_videos_and_filters(): void {
		$catalog = $this->catalog();

		$all = $catalog->list();
		$this->assertSame( [ 'post_id' => 7, 'video_id' => 'v1' ], $all['videos'][0]['linked_to'] );
		$this->assertNull( $all['videos'][1]['linked_to'] );

		$unlinked = $catalog->list( false, '', true );
		$this->assertSame( 2, $unlinked['total'] );

		$found = $catalog->list( false, 'ete lyon' );
		$this->assertSame( 'b', $found['videos'][0]['video']->id );
		$this->assertSame( 1, $found['total'] );

		$limited = $catalog->list( false, '', false, 2 );
		$this->assertCount( 2, $limited['videos'] );
		$this->assertSame( 3, $limited['total'] );
	}

	public function test_a_corrupt_cache_is_ignored(): void {
		$this->cache = [ 'fetched_at' => 1, 'videos' => 'x' ];
		$this->catalog()->list();
		$this->assertSame( 1, $this->reads );
	}

	public function test_folding(): void {
		$this->assertSame( 'ete a lyon', Folding::fold( '  Été, à LYON! ' ) );
		$this->assertTrue( Folding::matches( 'Grenoble ⇾ Salers', 'SALERS grenoble' ) );
		$this->assertFalse( Folding::matches( 'Grenoble', 'salers' ) );
		$this->assertTrue( Folding::matches( 'Grenoble', '' ) );
	}

	public function test_link_index_map_keeps_the_first_link_per_youtube_video(): void {
		$index = new LinkIndex( static fn(): iterable => [
			[ 'post_id' => 1, 'video_id' => 'v1', 'youtube_id' => 'a' ],
			[ 'post_id' => 2, 'video_id' => 'v2', 'youtube_id' => 'a' ],
			[ 'post_id' => 3, 'video_id' => 'v3', 'youtube_id' => 'b' ],
		] );
		$this->assertSame( [ 'a' => [ 'post_id' => 1, 'video_id' => 'v1' ], 'b' => [ 'post_id' => 3, 'video_id' => 'v3' ] ], $index->map() );
	}
}
