<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\YouTube;

use Closure;
use RuntimeException;

/**
 * The OAuth 2.0 requests the plugin makes to Google. It holds no state.
 */
final class OAuthClient {

	public const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
	public const TOKEN_ENDPOINT         = 'https://oauth2.googleapis.com/token';
	public const REVOCATION_ENDPOINT    = 'https://oauth2.googleapis.com/revoke';

	/**
	 * The single place to change when a later slice needs more access.
	 */
	public const SCOPES = [
		'https://www.googleapis.com/auth/youtube.upload',
		'https://www.googleapis.com/auth/youtube.force-ssl',
	];

	private string $clientId;

	private string $clientSecret;

	private string $redirectUri;

	/**
	 * @var Closure(string, string, array<string, string>, ?array<string, string>): array{status: int, body: array<string, mixed>}
	 */
	private Closure $http;

	/**
	 * @param Closure(string, string, array<string, string>, ?array<string, string>): array{status: int, body: array<string, mixed>} $http
	 *        Receives the method, URL, headers and form fields; throws RuntimeException on transport errors.
	 */
	public function __construct( string $clientId, string $clientSecret, string $redirectUri, Closure $http ) {
		$this->clientId     = $clientId;
		$this->clientSecret = $clientSecret;
		$this->redirectUri  = $redirectUri;
		$this->http         = $http;
	}

	public function authorizationUrl( string $state ): string {
		return self::AUTHORIZATION_ENDPOINT . '?' . http_build_query(
			[
				'client_id'              => $this->clientId,
				'redirect_uri'           => $this->redirectUri,
				'response_type'          => 'code',
				'scope'                  => implode( ' ', self::SCOPES ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * @return array{access_token: string, expires_in: int, refresh_token: string}
	 * @throws OAuthException
	 */
	public function exchangeCode( string $code ): array {
		$body = $this->tokenRequest(
			[
				'code'          => $code,
				'client_id'     => $this->clientId,
				'client_secret' => $this->clientSecret,
				'redirect_uri'  => $this->redirectUri,
				'grant_type'    => 'authorization_code',
			]
		);

		$refreshToken = (string) ( $body['refresh_token'] ?? '' );
		if ( '' === $refreshToken ) {
			throw new OAuthException( 'no_refresh_token', __( 'Google did not return a refresh token. Remove the access of this application in your Google account, then connect again.', 'wp-scatter-everywhere' ) );
		}

		return [
			'access_token'  => (string) $body['access_token'],
			'expires_in'    => (int) ( $body['expires_in'] ?? 0 ),
			'refresh_token' => $refreshToken,
		];
	}

	/**
	 * @return array{access_token: string, expires_in: int}
	 * @throws OAuthException
	 */
	public function refresh( string $refreshToken ): array {
		$body = $this->tokenRequest(
			[
				'refresh_token' => $refreshToken,
				'client_id'     => $this->clientId,
				'client_secret' => $this->clientSecret,
				'grant_type'    => 'refresh_token',
			]
		);

		return [
			'access_token' => (string) $body['access_token'],
			'expires_in'   => (int) ( $body['expires_in'] ?? 0 ),
		];
	}

	/**
	 * Revokes a token. Best effort: returns false instead of raising on failure.
	 */
	public function revoke( string $token ): bool {
		try {
			$response = ( $this->http )( 'POST', self::REVOCATION_ENDPOINT, [], [ 'token' => $token ] );
		} catch ( RuntimeException $e ) {
			return false;
		}

		return 200 === $response['status'];
	}

	/**
	 * @param array<string, string> $form
	 * @return array<string, mixed>
	 * @throws OAuthException
	 */
	private function tokenRequest( array $form ): array {
		try {
			$response = ( $this->http )( 'POST', self::TOKEN_ENDPOINT, [], $form );
		} catch ( RuntimeException $e ) {
			throw new OAuthException(
				'transport',
				sprintf(
					/* translators: %s: the error returned by the HTTP layer. */
					__( 'Google could not be reached: %s', 'wp-scatter-everywhere' ),
					$e->getMessage()
				)
			);
		}

		$body = $response['body'];

		if ( 200 !== $response['status'] || isset( $body['error'] ) || '' === (string) ( $body['access_token'] ?? '' ) ) {
			$code        = (string) ( $body['error'] ?? 'http_' . $response['status'] );
			$description = (string) ( $body['error_description'] ?? '' );

			throw new OAuthException(
				$code,
				sprintf(
					/* translators: 1: OAuth error code, 2: error description from Google (may be empty). */
					__( 'Google refused the request (%1$s). %2$s', 'wp-scatter-everywhere' ),
					$code,
					$description
				)
			);
		}

		return $body;
	}
}
