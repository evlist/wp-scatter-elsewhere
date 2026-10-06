<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

use Closure;
use RuntimeException;

/**
 * Sets the thumbnail of a video.
 */
final class ThumbnailClient {

	public const ENDPOINT = 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?uploadType=media&videoId=';

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
	 * @throws ThumbnailException
	 */
	public function set( string $youtubeId, string $imageData, string $mimeType = 'image/jpeg' ): void {
		$token = $this->tokens->getAccessToken();

		try {
			$response = ( $this->http )(
				'POST',
				self::ENDPOINT . rawurlencode( $youtubeId ),
				[
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => $mimeType,
				],
				$imageData
			);
		} catch ( RuntimeException $e ) {
			throw new ThumbnailException(
				sprintf(
					/* translators: %s: error returned by the HTTP layer. */
					__( 'The connection to YouTube failed: %s', 'wp-scatter-elsewhere' ),
					$e->getMessage()
				)
			);
		}

		if ( 200 === $response['status'] ) {
			return;
		}

		$message = ApiErrors::describe( $response['status'], $response['body'] );

		if ( 403 === $response['status'] && 'forbidden' === ApiErrors::reason( $response['body'] ) ) {
			$message .= ' ' . __( 'YouTube only allows custom thumbnails on verified channels (verify the account in YouTube Studio).', 'wp-scatter-elsewhere' );
		}

		throw new ThumbnailException( $message );
	}
}
