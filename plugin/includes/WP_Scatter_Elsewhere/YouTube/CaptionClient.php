<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

use Closure;
use RuntimeException;

/**
 * Lists, adds and replaces the caption tracks of a video.
 */
final class CaptionClient {

	public const DELETE_ENDPOINT = 'https://www.googleapis.com/youtube/v3/captions?id=';
	public const LIST_ENDPOINT   = 'https://www.googleapis.com/youtube/v3/captions?part=snippet&videoId=';
	public const UPLOAD_ENDPOINT = 'https://www.googleapis.com/upload/youtube/v3/captions?uploadType=multipart&part=snippet';

	private const MEDIA_TYPE = 'application/octet-stream';

	/**
	 * @var Closure(string, string, array<string, string>, string): array{status: int, headers: array<string, string>, body: string}
	 */
	private Closure $http;

	private AccessTokenProvider $tokens;

	/**
	 * @var Closure(): string
	 */
	private Closure $boundary;

	/**
	 * @param Closure(string, string, array<string, string>, string): array{status: int, headers: array<string, string>, body: string} $http
	 *        Receives the method, URL, headers and raw body; throws RuntimeException on transport errors.
	 * @param Closure(): string $boundary Returns a multipart boundary.
	 */
	public function __construct( Closure $http, AccessTokenProvider $tokens, Closure $boundary ) {
		$this->http     = $http;
		$this->tokens   = $tokens;
		$this->boundary = $boundary;
	}

	/**
	 * The standard caption tracks of a video (not the automatic ones), as language => caption id.
	 *
	 * @return array<string, string>
	 * @throws CaptionException
	 */
	public function standardTracks( string $youtubeId ): array {
		$tracks = [];
		foreach ( $this->tracks( $youtubeId ) as $track ) {
			if ( 'standard' === $track['kind'] ) {
				$tracks[ $track['language'] ] ??= $track['id'];
			}
		}

		return $tracks;
	}

	/**
	 * All the caption tracks of a video, with the state YouTube gives them.
	 *
	 * @return array<int, array{id: string, language: string, name: string, kind: string, status: string, failure: string, draft: bool}>
	 * @throws CaptionException
	 */
	public function tracks( string $youtubeId ): array {
		$response = $this->send( 'GET', self::LIST_ENDPOINT . rawurlencode( $youtubeId ), [ 'Authorization' => 'Bearer ' . $this->tokens->getAccessToken() ], '' );
		$data     = json_decode( $response['body'], true );

		$tracks = [];
		foreach ( is_array( $data ) ? (array) ( $data['items'] ?? [] ) : [] as $item ) {
			$snippet = is_array( $item['snippet'] ?? null ) ? $item['snippet'] : [];
			if ( isset( $item['id'], $snippet['language'] ) ) {
				$tracks[] = [
					'id'       => (string) $item['id'],
					'language' => (string) $snippet['language'],
					'name'     => (string) ( $snippet['name'] ?? '' ),
					'kind'     => (string) ( $snippet['trackKind'] ?? 'standard' ),
					'status'   => (string) ( $snippet['status'] ?? '' ),
					'failure'  => (string) ( $snippet['failureReason'] ?? '' ),
					'draft'    => (bool) ( $snippet['isDraft'] ?? false ),
				];
			}
		}

		return $tracks;
	}

	/**
	 * @param string $name Name of the track; YouTube refuses a track without one (invalidMetadata).
	 * @throws CaptionException
	 */
	public function insert( string $youtubeId, string $language, string $name, string $content ): void {
		$this->upload( 'POST', [ 'snippet' => [ 'videoId' => $youtubeId, 'language' => $language, 'name' => $name, 'isDraft' => false ] ], $content );
	}

	/**
	 * Replaces the file of an existing track.
	 *
	 * @throws CaptionException
	 */
	public function replace( string $captionId, string $content ): void {
		$this->upload( 'PUT', [ 'id' => $captionId, 'snippet' => [ 'isDraft' => false ] ], $content );
	}

	/**
	 * Deletes a caption track, including an automatic one when YouTube allows it.
	 *
	 * @throws CaptionException
	 */
	public function delete( string $captionId ): void {
		$this->send( 'DELETE', self::DELETE_ENDPOINT . rawurlencode( $captionId ), [ 'Authorization' => 'Bearer ' . $this->tokens->getAccessToken() ], '' );
	}

	/**
	 * @param array<string, mixed> $resource
	 */
	private function upload( string $method, array $resource, string $content ): void {
		$token     = $this->tokens->getAccessToken();
		$multipart = Multipart::related( $resource, $content, self::MEDIA_TYPE, ( $this->boundary )() );

		$this->send(
			$method,
			self::UPLOAD_ENDPOINT,
			[
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => $multipart['contentType'],
			],
			$multipart['body']
		);
	}

	/**
	 * @param array<string, string> $headers
	 * @return array{status: int, headers: array<string, string>, body: string}
	 * @throws CaptionException On transport errors and on any status but 200 and 204.
	 */
	private function send( string $method, string $url, array $headers, string $body ): array {
		try {
			$response = ( $this->http )( $method, $url, $headers, $body );
		} catch ( RuntimeException $e ) {
			throw new CaptionException(
				sprintf(
					/* translators: %s: error returned by the HTTP layer. */
					__( 'The connection to YouTube failed: %s', 'wp-scatter-elsewhere' ),
					$e->getMessage()
				)
			);
		}

		// 204 answers a deletion.
		if ( 200 !== $response['status'] && 204 !== $response['status'] ) {
			throw new CaptionException( ApiErrors::describe( $response['status'], $response['body'] ), ApiErrors::isQuota( $response['status'], $response['body'] ) );
		}

		return $response;
	}
}
