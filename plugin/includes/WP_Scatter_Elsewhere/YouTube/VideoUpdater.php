<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use WP_Scatter_Elsewhere\Metadata\KeywordNormalizer;

/**
 * Changes properties of a video that is already on YouTube, keeping all the others.
 *
 * The update call replaces the parts it is given and drops what they leave out, so the current video is
 * read first and its writable properties are sent back with the requested changes.
 */
final class VideoUpdater {

	public const VIDEOS_ENDPOINT = 'https://www.googleapis.com/youtube/v3/videos';

	/** Properties that can be changed, and the part of the video they belong to. */
	public const FIELDS = [ 'title', 'description', 'language', 'license', 'recording_date', 'keywords' ];

	/** Writable properties kept when the video is sent back, per part. */
	private const KEPT = [
		'snippet'          => [ 'title', 'description', 'tags', 'categoryId', 'defaultLanguage', 'defaultAudioLanguage' ],
		'status'           => [ 'privacyStatus', 'publishAt', 'license', 'embeddable', 'publicStatsViewable', 'selfDeclaredMadeForKids' ],
		'recordingDetails' => [ 'recordingDate', 'location', 'locationDescription' ],
	];

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
	 * @param array<string, string|string[]> $changes Values by field name, among self::FIELDS ("keywords" is a list that is added to
	 *                                                the existing keywords). Fields not listed are kept.
	 * @return string[] The new keywords that do not fit the limit of YouTube and were left out.
	 * @throws InvalidArgumentException          When a field is unknown or has no value.
	 * @throws VideoUpdateException              When the video cannot be read or updated.
	 * @throws NotConnectedException             When the plugin is not connected.
	 * @throws ReauthorizationRequiredException  When the authorisation was revoked.
	 * @throws OAuthException                    When no access token can be obtained.
	 */
	public function update( string $youtubeId, array $changes ): array {
		foreach ( $changes as $field => $value ) {
			$empty = is_array( $value ) ? [] === $value : '' === (string) $value;
			if ( ! in_array( $field, self::FIELDS, true ) || $empty || ( is_array( $value ) && 'keywords' !== $field ) ) {
				throw new InvalidArgumentException(
					sprintf(
						/* translators: %s: name of a video property. */
						__( 'Cannot update the property "%s".', 'wp-scatter-elsewhere' ),
						(string) $field
					)
				);
			}
		}

		$token   = $this->tokens->getAccessToken();
		$current = $this->read( $youtubeId, $token );
		$dropped = [];
		$video   = $this->merge( $current, $changes, $dropped );

		$parts = array_keys( array_filter( $video, 'is_array' ) );
		$body  = array_merge( [ 'id' => $youtubeId ], $video );

		$response = $this->send(
			'PUT',
			self::VIDEOS_ENDPOINT . '?part=' . implode( ',', $parts ),
			[
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json; charset=UTF-8',
			],
			// This class does not depend on WordPress, hence json_encode() rather than wp_json_encode().
			(string) json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);

		if ( 200 !== $response['status'] ) {
			throw new VideoUpdateException( ApiErrors::describe( $response['status'], $response['body'] ) );
		}

		return $dropped;
	}

	/**
	 * @return array<string, mixed> The video as returned by YouTube.
	 */
	private function read( string $youtubeId, string $token ): array {
		$response = $this->send(
			'GET',
			self::VIDEOS_ENDPOINT . '?part=snippet,status,recordingDetails&id=' . rawurlencode( $youtubeId ),
			[ 'Authorization' => 'Bearer ' . $token ],
			''
		);

		if ( 200 !== $response['status'] ) {
			throw new VideoUpdateException( ApiErrors::describe( $response['status'], $response['body'] ) );
		}

		$data = json_decode( $response['body'], true );
		$item = is_array( $data ) ? ( $data['items'][0] ?? null ) : null;

		if ( ! is_array( $item ) ) {
			throw new VideoUpdateException(
				sprintf(
					/* translators: %s: YouTube video id. */
					__( 'The video %s was not found on the connected channel.', 'wp-scatter-elsewhere' ),
					$youtubeId
				)
			);
		}

		return $item;
	}

	/**
	 * Keeps the writable properties of the current video and applies the changes.
	 *
	 * @param array<string, mixed>           $current
	 * @param array<string, string|string[]> $changes
	 * @param string[]                       $dropped Receives the new keywords that do not fit.
	 * @return array<string, array<string, mixed>>
	 */
	private function merge( array $current, array $changes, array &$dropped ): array {
		$video = [];
		foreach ( self::KEPT as $part => $properties ) {
			$source = is_array( $current[ $part ] ?? null ) ? $current[ $part ] : [];
			$video[ $part ] = array_intersect_key( $source, array_flip( $properties ) );
		}

		$map = [
			'title'          => [ 'snippet', 'title' ],
			'description'    => [ 'snippet', 'description' ],
			'language'       => [ 'snippet', 'defaultLanguage' ],
			'license'        => [ 'status', 'license' ],
			'recording_date' => [ 'recordingDetails', 'recordingDate' ],
		];

		foreach ( $changes as $field => $value ) {
			if ( 'keywords' === $field ) {
				$merged                   = ( new KeywordNormalizer() )->add( (array) ( $video['snippet']['tags'] ?? [] ), (array) $value );
				$video['snippet']['tags'] = $merged['kept'];
				$dropped                  = $merged['dropped'];
				continue;
			}

			[ $part, $property ] = $map[ $field ];

			$video[ $part ][ $property ] = $value;
			if ( 'language' === $field ) {
				$video['snippet']['defaultAudioLanguage'] = $value;
			}
		}

		// The description may be missing from a video that has none, but the snippet needs it.
		$video['snippet']['description'] ??= '';

		return array_filter( $video, static fn( array $properties ): bool => [] !== $properties );
	}

	/**
	 * @param array<string, string> $headers
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private function send( string $method, string $url, array $headers, string $body ): array {
		try {
			return ( $this->http )( $method, $url, $headers, $body );
		} catch ( RuntimeException $e ) {
			throw new VideoUpdateException(
				sprintf(
					/* translators: %s: error returned by the HTTP layer. */
					__( 'The connection to YouTube failed: %s', 'wp-scatter-elsewhere' ),
					$e->getMessage()
				)
			);
		}
	}
}
