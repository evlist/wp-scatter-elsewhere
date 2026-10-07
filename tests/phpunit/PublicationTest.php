<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Publication\Publication;
use WP_Scatter_Elsewhere\Publication\PublicationLinks;
use WP_Scatter_Elsewhere\Publication\PublicationStore;

class PublicationTest extends TestCase {

	/** @var array<int, mixed> */
	private array $meta = [];

	private function store(): PublicationStore {
		return new PublicationStore(
			fn( int $postId ): mixed => $this->meta[ $postId ] ?? '',
			function ( int $postId, array $value ): void {
				$this->meta[ $postId ] = $value;
			}
		);
	}

	public function test_url_and_privacy(): void {
		$publication = new Publication( 'v1', 'abcDEF_-123', 'private', 5, 'j1' );

		$this->assertSame( 'https://www.youtube.com/watch?v=abcDEF_-123', $publication->url() );
		$this->assertTrue( $publication->isPrivate() );
		$this->assertFalse( ( new Publication( 'v1', 'abcDEF_-123', null, 5, null ) )->isPrivate() );
	}

	public function test_youtube_id_shape(): void {
		$this->assertTrue( Publication::isValidYouTubeId( '9FzZpnEKL-s' ) );
		$this->assertFalse( Publication::isValidYouTubeId( '9FzZpnEKL-' ) );
		$this->assertFalse( Publication::isValidYouTubeId( '9FzZpnEKL-sx' ) );
		$this->assertFalse( Publication::isValidYouTubeId( '9FzZpnEKL s' ) );
		$this->assertFalse( Publication::isValidYouTubeId( '' ) );
	}

	public function test_store_round_trip_keeps_the_recording_order(): void {
		$store = $this->store();
		$store->save( 7, new Publication( 'b', 'bbbbbbbbbbb', 'public', 2, 'j2' ) );
		$store->save( 7, new Publication( 'a', 'aaaaaaaaaaa', null, 1, null ) );

		$all = $store->forPost( 7 );

		$this->assertSame( [ 'b', 'a' ], array_keys( $all ) );
		$this->assertSame( 'public', $all['b']->privacy );
		$this->assertNull( $all['a']->privacy );
		$this->assertNull( $all['a']->jobId );
		$this->assertSame( 'aaaaaaaaaaa', $store->get( 7, 'a' )->youtubeId );
		$this->assertNull( $store->get( 7, 'zzz' ) );
		$this->assertSame( [], $store->forPost( 8 ) );
	}

	public function test_saving_a_video_again_replaces_its_record(): void {
		$store = $this->store();
		$store->save( 7, new Publication( 'a', 'aaaaaaaaaaa', 'private', 1, 'j1' ) );
		$store->save( 7, new Publication( 'a', 'ccccccccccc', 'public', 2, 'j2' ) );

		$this->assertCount( 1, $store->forPost( 7 ) );
		$this->assertSame( 'ccccccccccc', $store->get( 7, 'a' )->youtubeId );
	}

	public function test_malformed_meta_is_ignored(): void {
		$this->meta[7] = [ 'a' => 'garbage', 'b' => [ 'privacy' => 'public' ], 'c' => [ 'youtube_id' => 'ccccccccccc' ] ];

		$this->assertSame( [ 'c' ], array_keys( $this->store()->forPost( 7 ) ) );
		$this->meta[8] = 'garbage';
		$this->assertSame( [], $this->store()->forPost( 8 ) );
	}

	public function test_link_selection_skips_private_videos_unless_asked(): void {
		$all = [
			'a' => new Publication( 'a', 'aaaaaaaaaaa', 'private', 1, null ),
			'b' => new Publication( 'b', 'bbbbbbbbbbb', 'unlisted', 2, null ),
			'c' => new Publication( 'c', 'ccccccccccc', null, 3, null ),
		];

		$this->assertSame( 'b', PublicationLinks::select( $all, null, false )->videoId );
		$this->assertSame( 'a', PublicationLinks::select( $all, null, true )->videoId );
		$this->assertSame( 'c', PublicationLinks::select( $all, 'c', false )->videoId );
		$this->assertNull( PublicationLinks::select( $all, 'a', false ) );
		$this->assertSame( 'a', PublicationLinks::select( $all, 'a', true )->videoId );
		$this->assertNull( PublicationLinks::select( $all, 'zzz', true ) );
		$this->assertNull( PublicationLinks::select( [], null, true ) );
	}

	public function test_the_time_of_the_last_check_is_kept_only_when_there_is_one(): void {
		$never   = new Publication( 'a', 'aaaaaaaaaaa', 'private', 1, 'j1' );
		$checked = new Publication( 'a', 'aaaaaaaaaaa', 'public', 1, 'j1', 99 );

		$this->assertArrayNotHasKey( 'checked_at', $never->toArray() );
		$this->assertSame( 99, $checked->toArray()['checked_at'] );
		$this->assertNull( Publication::fromArray( 'a', $never->toArray() )->checkedAt );
		$this->assertSame( 99, Publication::fromArray( 'a', $checked->toArray() )->checkedAt );
	}

	public function test_a_record_can_be_removed(): void {
		$store = $this->store();
		$store->save( 7, new Publication( 'a', 'aaaaaaaaaaa', 'private', 1, null ) );
		$store->save( 7, new Publication( 'b', 'bbbbbbbbbbb', 'public', 2, null ) );

		$this->assertTrue( $store->remove( 7, 'a' ) );
		$this->assertSame( [ 'b' ], array_keys( $store->forPost( 7 ) ) );
		$this->assertFalse( $store->remove( 7, 'a' ) );
		$this->assertFalse( $store->remove( 8, 'a' ) );
	}
}
