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

	public const ENDPOINT = 'https://www.googleapis.com/youtube/v3/videos?part=status&id=';

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
		try {
			$response = ( $this->http )( 'GET', self::ENDPOINT . rawurlencode( $youtubeId ), [ 'Authorization' => 'Bearer ' . $this->tokens->getAccessToken() ], '' );
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

		$data    = json_decode( $response['body'], true );
		$privacy = is_array( $data ) ? (string) ( $data['items'][0]['status']['privacyStatus'] ?? '' ) : '';

		if ( '' === $privacy ) {
			throw new VideoUpdateException(
				sprintf(
					/* translators: %s: YouTube video id. */
					__( 'The video %s was not found on the connected channel.', 'wp-scatter-elsewhere' ),
					$youtubeId
				)
			);
		}

		return $privacy;
	}
}
