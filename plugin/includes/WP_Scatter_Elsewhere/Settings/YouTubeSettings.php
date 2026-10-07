<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Settings;

use Closure;

/**
 * OAuth credentials and connection state of the YouTube destination.
 *
 * Stored in a single option. Secrets are kept in clear text, which is accepted for the
 * trusted-admin model (see docs/slices/001-youtube-connection.md).
 */
class YouTubeSettings {

	private const OPTION_KEY = 'wp_scatter_elsewhere_youtube';

	public const STATUS_DISCONNECTED = 'disconnected';
	public const STATUS_CONNECTED    = 'connected';
	public const STATUS_NEEDS_REAUTH = 'needs_reauth';

	private const DEFAULTS = [
		'client_id'     => '',
		'client_secret' => '',
		'refresh_token' => '',
		'connected_at'  => 0,
		'channel_id'    => '',
		'channel_title' => '',
		'status'        => self::STATUS_DISCONNECTED,
	];

	/**
	 * @var Closure(): mixed
	 */
	private Closure $loader;

	/**
	 * @var Closure(array<string, mixed>): void
	 */
	private Closure $saver;

	/**
	 * @param Closure(): mixed                     $loader
	 * @param Closure(array<string, mixed>): void $saver
	 */
	public function __construct( Closure $loader, Closure $saver ) {
		$this->loader = $loader;
		$this->saver  = $saver;
	}

	public static function optionKey(): string {
		return self::OPTION_KEY;
	}

	/**
	 * @return array{client_id: string, client_secret: string, refresh_token: string, connected_at: int, channel_id: string, channel_title: string, status: string}
	 */
	public function all(): array {
		$stored = ( $this->loader )();
		$stored = is_array( $stored ) ? $stored : [];

		$values = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			$value          = $stored[ $key ] ?? $default;
			$values[ $key ] = is_int( $default ) ? (int) $value : (string) $value;
		}

		if ( '' === $values['refresh_token'] && self::STATUS_NEEDS_REAUTH !== $values['status'] ) {
			$values['status'] = self::STATUS_DISCONNECTED;
		}

		if ( ! in_array( $values['status'], [ self::STATUS_DISCONNECTED, self::STATUS_CONNECTED, self::STATUS_NEEDS_REAUTH ], true ) ) {
			$values['status'] = self::STATUS_DISCONNECTED;
		}

		if ( '' !== $values['refresh_token'] && self::STATUS_DISCONNECTED === $values['status'] ) {
			$values['status'] = self::STATUS_CONNECTED;
		}

		return $values;
	}

	public function clientId(): string {
		return $this->all()['client_id'];
	}

	public function clientSecret(): string {
		return $this->all()['client_secret'];
	}

	public function refreshToken(): string {
		return $this->all()['refresh_token'];
	}

	public function status(): string {
		return $this->all()['status'];
	}

	public function channelId(): string {
		return $this->all()['channel_id'];
	}

	public function channelTitle(): string {
		return $this->all()['channel_title'];
	}

	public function connectedAt(): int {
		return $this->all()['connected_at'];
	}

	public function hasCredentials(): bool {
		$all = $this->all();

		return '' !== $all['client_id'] && '' !== $all['client_secret'];
	}

	public function isConnected(): bool {
		return self::STATUS_CONNECTED === $this->status();
	}

	/**
	 * Saves the credentials. An empty secret keeps the stored one. Changing the credentials drops the
	 * connection, since a refresh token only works with the client that obtained it.
	 */
	public function saveCredentials( string $clientId, string $clientSecret ): void {
		$all          = $this->all();
		$clientId     = trim( $clientId );
		$clientSecret = '' === trim( $clientSecret ) ? $all['client_secret'] : trim( $clientSecret );

		if ( $clientId !== $all['client_id'] || $clientSecret !== $all['client_secret'] ) {
			$all = array_merge( $all, $this->disconnected() );
		}

		$all['client_id']     = $clientId;
		$all['client_secret'] = $clientSecret;

		( $this->saver )( $all );
	}

	public function saveConnection( string $refreshToken, string $channelId, string $channelTitle, int $connectedAt ): void {
		( $this->saver )(
			array_merge(
				$this->all(),
				[
					'refresh_token' => $refreshToken,
					'connected_at'  => $connectedAt,
					'channel_id'    => $channelId,
					'channel_title' => $channelTitle,
					'status'        => self::STATUS_CONNECTED,
				]
			)
		);
	}

	/**
	 * The refresh token is no longer valid: forget it but keep the channel information for display.
	 */
	public function markNeedsReauth(): void {
		( $this->saver )(
			array_merge(
				$this->all(),
				[
					'refresh_token' => '',
					'status'        => self::STATUS_NEEDS_REAUTH,
				]
			)
		);
	}

	/**
	 * Removes the connection and keeps the client id and secret.
	 */
	public function clearConnection(): void {
		( $this->saver )( array_merge( $this->all(), $this->disconnected() ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function disconnected(): array {
		return [
			'refresh_token' => '',
			'connected_at'  => 0,
			'channel_id'    => '',
			'channel_title' => '',
			'status'        => self::STATUS_DISCONNECTED,
		];
	}
}
