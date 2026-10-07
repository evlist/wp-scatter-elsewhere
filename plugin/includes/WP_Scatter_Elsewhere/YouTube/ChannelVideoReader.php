<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

use Closure;
use RuntimeException;

/**
 * Reads the uploads of the connected channel, private and unlisted videos included.
 *
 * Quota: one unit for the channel, one per page of 50 uploads, one per batch of 50 videos.
 */
final class ChannelVideoReader {

	public const CHANNEL_ENDPOINT = 'https://www.googleapis.com/youtube/v3/channels?part=contentDetails&mine=true';
	public const ITEMS_ENDPOINT   = 'https://www.googleapis.com/youtube/v3/playlistItems?part=contentDetails&maxResults=50&playlistId=';
	public const VIDEOS_ENDPOINT  = 'https://www.googleapis.com/youtube/v3/videos?part=snippet,status,recordingDetails&maxResults=50&id=';

	private const PAGE_SIZE = 50;

	/**
	 * @var Closure(string, string, array<string, string>, string): array{status: int, headers: array<string, string>, body: string}
	 */
	private Closure $http;

	private AccessTokenProvider $tokens;

	/**
	 * @param Closure(string, string, array<string, string>, string): array{status: int, headers: array<string, string>, body: string} $http
	 *        Receives the method, URL, headers and raw body; throws RuntimeException on transport errors.
	 */
	public function __construct( Closure $http, AccessTokenProvider $tokens ) {
		$this->http   = $http;
		$this->tokens = $tokens;
	}

	/**
	 * The most recent uploads first, at most $limit of them.
	 *
	 * @return CatalogVideo[]
	 * @throws ChannelCatalogException
	 */
	public function read( int $limit ): array {
		$limit = max( 1, $limit );
		$ids   = $this->uploadIds( $this->uploadsPlaylist(), $limit );

		$videos = [];
		foreach ( array_chunk( $ids, self::PAGE_SIZE ) as $chunk ) {
			$data = $this->get( self::VIDEOS_ENDPOINT . rawurlencode( implode( ',', $chunk ) ) );

			foreach ( (array) ( $data['items'] ?? [] ) as $item ) {
				$video = $this->video( is_array( $item ) ? $item : [] );
				if ( null !== $video ) {
					$videos[ $video->id ] = $video;
				}
			}
		}

		// The order of the uploads playlist is the order of the channel, the order of videos.list is not guaranteed.
		$ordered = [];
		foreach ( $ids as $id ) {
			if ( isset( $videos[ $id ] ) ) {
				$ordered[] = $videos[ $id ];
			}
		}

		return $ordered;
	}

	/**
	 * @throws ChannelCatalogException
	 */
	private function uploadsPlaylist(): string {
		$data     = $this->get( self::CHANNEL_ENDPOINT );
		$playlist = (string) ( $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '' );

		if ( '' === $playlist ) {
			throw new ChannelCatalogException( __( 'YouTube did not return the uploads of the connected channel.', 'wp-scatter-elsewhere' ) );
		}

		return $playlist;
	}

	/**
	 * @return string[]
	 * @throws ChannelCatalogException
	 */
	private function uploadIds( string $playlist, int $limit ): array {
		$ids       = [];
		$pageToken = '';

		do {
			$data = $this->get( self::ITEMS_ENDPOINT . rawurlencode( $playlist ) . ( '' !== $pageToken ? '&pageToken=' . rawurlencode( $pageToken ) : '' ) );

			foreach ( (array) ( $data['items'] ?? [] ) as $item ) {
				$id = is_array( $item ) ? (string) ( $item['contentDetails']['videoId'] ?? '' ) : '';
				if ( '' !== $id ) {
					$ids[ $id ] = $id;
				}
			}

			$pageToken = (string) ( $data['nextPageToken'] ?? '' );
		} while ( '' !== $pageToken && count( $ids ) < $limit );

		return array_slice( array_values( $ids ), 0, $limit );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function video( array $item ): ?CatalogVideo {
		$id = (string) ( $item['id'] ?? '' );
		if ( '' === $id ) {
			return null;
		}

		$thumbnails = (array) ( $item['snippet']['thumbnails'] ?? [] );
		$thumbnail  = $thumbnails['medium']['url'] ?? $thumbnails['default']['url'] ?? null;
		$recording  = $item['recordingDetails']['recordingDate'] ?? null;

		return new CatalogVideo(
			$id,
			(string) ( $item['snippet']['title'] ?? '' ),
			(string) ( $item['snippet']['publishedAt'] ?? '' ),
			(string) ( $item['status']['privacyStatus'] ?? '' ),
			(string) ( $item['snippet']['description'] ?? '' ),
			null === $recording ? null : (string) $recording,
			null === $thumbnail ? null : (string) $thumbnail
		);
	}

	/**
	 * @return array<string, mixed>
	 * @throws ChannelCatalogException
	 */
	private function get( string $url ): array {
		try {
			$response = ( $this->http )( 'GET', $url, [ 'Authorization' => 'Bearer ' . $this->tokens->getAccessToken() ], '' );
		} catch ( RuntimeException $e ) {
			throw new ChannelCatalogException(
				sprintf(
					/* translators: %s: error returned by the HTTP layer. */
					__( 'The connection to YouTube failed: %s', 'wp-scatter-elsewhere' ),
					$e->getMessage()
				)
			);
		}

		if ( 200 !== $response['status'] ) {
			throw new ChannelCatalogException( ApiErrors::describe( $response['status'], $response['body'] ) );
		}

		$data = json_decode( $response['body'], true );

		return is_array( $data ) ? $data : [];
	}
}
