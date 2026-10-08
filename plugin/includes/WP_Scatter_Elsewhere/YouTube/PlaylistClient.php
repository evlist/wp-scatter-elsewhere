<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

use Closure;
use RuntimeException;

/**
 * Lists the playlists of the channel and puts videos in them.
 */
final class PlaylistClient {

	public const PLAYLISTS_ENDPOINT = 'https://www.googleapis.com/youtube/v3/playlists?part=snippet&mine=true&maxResults=50';
	public const ITEMS_ENDPOINT     = 'https://www.googleapis.com/youtube/v3/playlistItems';

	/** Safety limit on the pages read, 50 playlists each. */
	private const MAX_PAGES = 10;

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
	 * The playlists of the channel, as id => title.
	 *
	 * @return array<string, string>
	 * @throws PlaylistException
	 */
	public function playlists(): array {
		$playlists = [];
		$pageToken = '';

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$url      = self::PLAYLISTS_ENDPOINT . ( '' !== $pageToken ? '&pageToken=' . rawurlencode( $pageToken ) : '' );
			$response = $this->send( 'GET', $url, '' );
			$data     = json_decode( $response['body'], true );
			$data     = is_array( $data ) ? $data : [];

			foreach ( (array) ( $data['items'] ?? [] ) as $item ) {
				if ( isset( $item['id'] ) ) {
					$playlists[ (string) $item['id'] ] = (string) ( $item['snippet']['title'] ?? $item['id'] );
				}
			}

			$pageToken = (string) ( $data['nextPageToken'] ?? '' );
			if ( '' === $pageToken ) {
				break;
			}
		}

		return $playlists;
	}

	/**
	 * Whether a playlist already contains a video.
	 *
	 * @throws PlaylistException
	 */
	public function contains( string $playlistId, string $youtubeId ): bool {
		$response = $this->send( 'GET', self::ITEMS_ENDPOINT . '?part=id&maxResults=1&playlistId=' . rawurlencode( $playlistId ) . '&videoId=' . rawurlencode( $youtubeId ), '' );
		$data     = json_decode( $response['body'], true );

		return is_array( $data ) && [] !== (array) ( $data['items'] ?? [] );
	}

	/**
	 * The ids of the items of a playlist that hold a video (normally one).
	 *
	 * @return string[]
	 * @throws PlaylistException
	 */
	public function itemIds( string $playlistId, string $youtubeId ): array {
		$response = $this->send( 'GET', self::ITEMS_ENDPOINT . '?part=id&maxResults=50&playlistId=' . rawurlencode( $playlistId ) . '&videoId=' . rawurlencode( $youtubeId ), '' );
		$data     = json_decode( $response['body'], true );
		$ids      = [];

		foreach ( is_array( $data ) ? (array) ( $data['items'] ?? [] ) : [] as $item ) {
			if ( isset( $item['id'] ) ) {
				$ids[] = (string) $item['id'];
			}
		}

		return $ids;
	}

	/**
	 * Takes an item out of its playlist.
	 *
	 * @throws PlaylistException
	 */
	public function remove( string $itemId ): void {
		$this->send( 'DELETE', self::ITEMS_ENDPOINT . '?id=' . rawurlencode( $itemId ), '' );
	}

	/**
	 * @throws PlaylistException
	 */
	public function add( string $playlistId, string $youtubeId ): void {
		$this->send(
			'POST',
			self::ITEMS_ENDPOINT . '?part=snippet',
			// This class does not depend on WordPress, hence json_encode() rather than wp_json_encode().
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				[
					'snippet' => [
						'playlistId' => $playlistId,
						'resourceId' => [ 'kind' => 'youtube#video', 'videoId' => $youtubeId ],
					],
				]
			)
		);
	}

	/**
	 * @return array{status: int, headers: array<string, string>, body: string}
	 * @throws PlaylistException On transport errors and on any status but 200.
	 */
	private function send( string $method, string $url, string $body ): array {
		$headers = [ 'Authorization' => 'Bearer ' . $this->tokens->getAccessToken() ];
		if ( '' !== $body ) {
			$headers['Content-Type'] = 'application/json; charset=UTF-8';
		}

		try {
			$response = ( $this->http )( $method, $url, $headers, $body );
		} catch ( RuntimeException $e ) {
			throw new PlaylistException(
				sprintf(
					/* translators: %s: error returned by the HTTP layer. */
					__( 'The connection to YouTube failed: %s', 'wp-scatter-elsewhere' ),
					$e->getMessage()
				)
			);
		}

		// A deletion is answered with 204 (no content).
		if ( 200 !== $response['status'] && ! ( 'DELETE' === $method && 204 === $response['status'] ) ) {
			throw new PlaylistException( ApiErrors::describe( $response['status'], $response['body'] ), ApiErrors::isQuota( $response['status'], $response['body'] ) );
		}

		return $response;
	}
}
