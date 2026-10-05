<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\YouTube;

use Closure;
use WP_Scatter_Everywhere\Settings\YouTubeSettings;

/**
 * Returns a valid access token, refreshing it when needed.
 */
final class AccessTokenProvider {

	private const MARGIN_SECONDS = 60;

	private YouTubeSettings $settings;

	private OAuthClient $oauth;

	/**
	 * @var Closure(): ?array{token: string, expires_at: int}
	 */
	private Closure $cacheGet;

	/**
	 * @var Closure(array{token: string, expires_at: int}, int): void
	 */
	private Closure $cacheSet;

	/**
	 * @var Closure(): void
	 */
	private Closure $cacheDelete;

	/**
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * @param Closure(): ?array{token: string, expires_at: int}         $cacheGet
	 * @param Closure(array{token: string, expires_at: int}, int): void $cacheSet    Receives the value and its lifetime in seconds.
	 * @param Closure(): void                                           $cacheDelete
	 * @param Closure(): int                                            $clock       Current Unix time.
	 */
	public function __construct( YouTubeSettings $settings, OAuthClient $oauth, Closure $cacheGet, Closure $cacheSet, Closure $cacheDelete, Closure $clock ) {
		$this->settings    = $settings;
		$this->oauth       = $oauth;
		$this->cacheGet    = $cacheGet;
		$this->cacheSet    = $cacheSet;
		$this->cacheDelete = $cacheDelete;
		$this->clock       = $clock;
	}

	/**
	 * @throws NotConnectedException            When the plugin is not connected.
	 * @throws ReauthorizationRequiredException When Google refuses the refresh token.
	 * @throws OAuthException                   For any other failure; the connection is left untouched.
	 */
	public function getAccessToken(): string {
		$refreshToken = $this->settings->refreshToken();

		if ( '' === $refreshToken ) {
			if ( YouTubeSettings::STATUS_NEEDS_REAUTH === $this->settings->status() ) {
				throw new ReauthorizationRequiredException( __( 'The YouTube authorisation has expired or was revoked. Connect again on the settings page.', 'wp-scatter-everywhere' ) );
			}

			throw new NotConnectedException( __( 'The plugin is not connected to YouTube.', 'wp-scatter-everywhere' ) );
		}

		$now    = ( $this->clock )();
		$cached = ( $this->cacheGet )();

		if ( null !== $cached && $cached['expires_at'] - $now > self::MARGIN_SECONDS ) {
			return $cached['token'];
		}

		try {
			$fresh = $this->oauth->refresh( $refreshToken );
		} catch ( OAuthException $e ) {
			if ( $e->isInvalidGrant() ) {
				$this->settings->markNeedsReauth();
				( $this->cacheDelete )();

				throw new ReauthorizationRequiredException( __( 'The YouTube authorisation has expired or was revoked. Connect again on the settings page.', 'wp-scatter-everywhere' ), 0, $e );
			}

			throw $e;
		}

		( $this->cacheSet )( [ 'token' => $fresh['access_token'], 'expires_at' => $now + $fresh['expires_in'] ], $fresh['expires_in'] );

		return $fresh['access_token'];
	}
}
