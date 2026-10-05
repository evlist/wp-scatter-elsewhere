<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\YouTube;

use RuntimeException;
use WP_Scatter_Everywhere\Settings\YouTubeSettings;

/**
 * Wires the YouTube connection classes to WordPress. Contains no logic worth testing without WordPress.
 */
final class WordPressFactory {

	public const CALLBACK_ACTION = 'wp_scatter_everywhere_youtube_callback';

	private const TOKEN_TRANSIENT = 'wp_scatter_everywhere_youtube_access_token';
	private const STATE_TRANSIENT = 'wp_scatter_everywhere_oauth_state_';

	public static function settings(): YouTubeSettings {
		return new YouTubeSettings(
			static fn(): mixed => get_option( YouTubeSettings::optionKey(), false ),
			static function ( array $value ): void {
				// Secrets must not be loaded on every request: autoload is disabled.
				update_option( YouTubeSettings::optionKey(), $value, false );
			}
		);
	}

	public static function redirectUri(): string {
		return admin_url( 'admin-post.php?action=' . self::CALLBACK_ACTION );
	}

	public static function oauthClient( YouTubeSettings $settings ): OAuthClient {
		return new OAuthClient( $settings->clientId(), $settings->clientSecret(), self::redirectUri(), self::http() );
	}

	public static function connectionService( YouTubeSettings $settings ): ConnectionService {
		$http = self::http();

		return new ConnectionService(
			$settings,
			self::oauthClient( $settings ),
			new ChannelClient( $http ),
			new OAuthStateStore(
				static function ( int $userId ): ?string {
					$state = get_transient( self::STATE_TRANSIENT . $userId );

					return is_string( $state ) ? $state : null;
				},
				static function ( int $userId, string $state, int $lifetime ): void {
					set_transient( self::STATE_TRANSIENT . $userId, $state, $lifetime );
				},
				static function ( int $userId ): void {
					delete_transient( self::STATE_TRANSIENT . $userId );
				},
				static fn(): string => bin2hex( random_bytes( 16 ) )
			),
			static function (): void {
				delete_transient( self::TOKEN_TRANSIENT );
			},
			static fn(): int => time()
		);
	}

	public static function accessTokenProvider( YouTubeSettings $settings ): AccessTokenProvider {
		return new AccessTokenProvider(
			$settings,
			self::oauthClient( $settings ),
			static function (): ?array {
				$cached = get_transient( self::TOKEN_TRANSIENT );

				return is_array( $cached ) && isset( $cached['token'], $cached['expires_at'] ) ? $cached : null;
			},
			static function ( array $value, int $lifetime ): void {
				set_transient( self::TOKEN_TRANSIENT, $value, max( 1, $lifetime ) );
			},
			static function (): void {
				delete_transient( self::TOKEN_TRANSIENT );
			},
			static fn(): int => time()
		);
	}

	/**
	 * @return \Closure(string, string, array<string, string>, ?array<string, string>): array{status: int, body: array<string, mixed>}
	 */
	private static function http(): \Closure {
		return static function ( string $method, string $url, array $headers, ?array $form ): array {
			$args = [
				'method'  => $method,
				'timeout' => 20,
				'headers' => $headers,
			];
			if ( null !== $form ) {
				$args['body'] = $form;
			}

			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				throw new RuntimeException( $response->get_error_message() );
			}

			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			return [
				'status' => (int) wp_remote_retrieve_response_code( $response ),
				'body'   => is_array( $body ) ? $body : [],
			];
		};
	}
}
