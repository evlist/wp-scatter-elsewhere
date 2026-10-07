<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

use Closure;
use RuntimeException;

/**
 * Reads the current state of a video of YouTube.
 */
final class VideoInspector {

	public const ENDPOINT         = 'https://www.googleapis.com/youtube/v3/videos?part=status&id=';
	public const DETAILS_ENDPOINT = 'https://www.googleapis.com/youtube/v3/videos?part=snippet,status&id=';

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
	 * The privacy status of a video: "private", "unlisted" or "public".
	 *
	 * @throws VideoUpdateException When the video cannot be read or is not on the connected channel.
	 */
	public function privacy( string $youtubeId ): string {
		$item    = $this->read( self::ENDPOINT . rawurlencode( $youtubeId ), $youtubeId );
		$privacy = (string) ( $item['status']['privacyStatus'] ?? '' );

		if ( '' === $privacy ) {
			throw $this->notFound( $youtubeId );
		}

		return $privacy;
	}

	/**
	 * The title, privacy, upload date and channel of a video.
	 *
	 * @throws VideoUpdateException When the video cannot be read or does not exist.
	 */
	public function details( string $youtubeId ): VideoDetails {
		$item = $this->read( self::DETAILS_ENDPOINT . rawurlencode( $youtubeId ), $youtubeId );

		return new VideoDetails(
			$youtubeId,
			(string) ( $item['snippet']['title'] ?? '' ),
			(string) ( $item['status']['privacyStatus'] ?? '' ),
			(string) ( $item['snippet']['publishedAt'] ?? '' ),
			(string) ( $item['snippet']['channelId'] ?? '' )
		);
	}

	/**
	 * @return array<string, mixed> The first item of the answer.
	 * @throws VideoUpdateException
	 */
	private function read( string $url, string $youtubeId ): array {
		try {
			$response = ( $this->http )( 'GET', $url, [ 'Authorization' => 'Bearer ' . $this->tokens->getAccessToken() ], '' );
		} catch ( RuntimeException $e ) {
			throw new VideoUpdateException(
				sprintf(
					/* translators: %s: error returned by the HTTP layer. */
					__( 'The connection to YouTube failed: %s', 'wp-scatter-elsewhere' ),
					$e->getMessage()
				)
			);
		}

		if ( 200 !== $response['status'] ) {
			throw new VideoUpdateException( ApiErrors::describe( $response['status'], $response['body'] ) );
		}

		$data = json_decode( $response['body'], true );
		$item = is_array( $data ) ? ( $data['items'][0] ?? null ) : null;

		if ( ! is_array( $item ) ) {
			throw $this->notFound( $youtubeId );
		}

		return $item;
	}

	private function notFound( string $youtubeId ): VideoUpdateException {
		return new VideoUpdateException(
			sprintf(
				/* translators: %s: YouTube video id. */
				__( 'The video %s was not found on the connected channel.', 'wp-scatter-elsewhere' ),
				$youtubeId
			)
		);
	}
}
