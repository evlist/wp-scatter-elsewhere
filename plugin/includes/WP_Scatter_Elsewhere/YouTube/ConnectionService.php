<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

use Closure;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;

/**
 * The connect and disconnect flows. WordPress-independent: the admin pages only call it.
 */
final class ConnectionService {

	private YouTubeSettings $settings;

	private OAuthClient $oauth;

	private ChannelClient $channels;

	private OAuthStateStore $states;

	/**
	 * @var Closure(): void
	 */
	private Closure $clearTokenCache;

	/**
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * @param Closure(): void $clearTokenCache Forgets the cached access token.
	 * @param Closure(): int  $clock           Current Unix time.
	 */
	public function __construct( YouTubeSettings $settings, OAuthClient $oauth, ChannelClient $channels, OAuthStateStore $states, Closure $clearTokenCache, Closure $clock ) {
		$this->settings        = $settings;
		$this->oauth           = $oauth;
		$this->channels        = $channels;
		$this->states          = $states;
		$this->clearTokenCache = $clearTokenCache;
		$this->clock           = $clock;
	}

	/**
	 * Returns the Google URL the administrator has to be sent to.
	 *
	 * @throws OAuthException When the credentials are not saved yet.
	 */
	public function startAuthorization( int $userId ): string {
		if ( ! $this->settings->hasCredentials() ) {
			throw new OAuthException( 'missing_credentials', __( 'Save the client ID and the client secret first.', 'wp-scatter-elsewhere' ) );
		}

		return $this->oauth->authorizationUrl( $this->states->issue( $userId ) );
	}

	/**
	 * Handles the callback of Google.
	 *
	 * @param array<string, string> $query The `code`, `state` and `error` query parameters.
	 * @throws OAuthException
	 */
	public function completeAuthorization( int $userId, array $query ): void {
		$stateIsValid = $this->states->consume( $userId, $query['state'] ?? '' );

		if ( ! $stateIsValid ) {
			throw new OAuthException( 'invalid_state', __( 'The authorisation request is invalid or has expired. Try again.', 'wp-scatter-elsewhere' ) );
		}

		if ( '' !== ( $query['error'] ?? '' ) ) {
			throw new OAuthException(
				$query['error'],
				sprintf(
					/* translators: %s: error code returned by Google, such as "access_denied". */
					__( 'The authorisation was not granted (%s).', 'wp-scatter-elsewhere' ),
					$query['error']
				)
			);
		}

		if ( '' === ( $query['code'] ?? '' ) ) {
			throw new OAuthException( 'missing_code', __( 'Google did not return an authorisation code.', 'wp-scatter-elsewhere' ) );
		}

		$tokens  = $this->oauth->exchangeCode( $query['code'] );
		$channel = $this->channels->fetch( $tokens['access_token'] );

		$this->settings->saveConnection( $tokens['refresh_token'], $channel['id'] ?? '', $channel['title'] ?? '', ( $this->clock )() );
		( $this->clearTokenCache )();
	}

	/**
	 * Revokes the token (best effort) and removes the connection locally. Client ID and secret are kept.
	 */
	public function disconnect(): void {
		$refreshToken = $this->settings->refreshToken();

		if ( '' !== $refreshToken ) {
			$this->oauth->revoke( $refreshToken );
		}

		$this->settings->clearConnection();
		( $this->clearTokenCache )();
	}
}
