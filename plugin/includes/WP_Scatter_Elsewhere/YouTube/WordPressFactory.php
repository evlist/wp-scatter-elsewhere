<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

use RuntimeException;
use WP_Scatter_Elsewhere\Publication\WordPressFactory as PublicationFactory;
use WP_Scatter_Elsewhere\Settings\UploadSettings;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;
use WP_Scatter_Elsewhere\Subtitles\SubtitleConverter;
use WP_Scatter_Elsewhere\Subtitles\SubtitleService;
use WP_Scatter_Elsewhere\YouTube\Upload\ResumableUploader;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadJobStore;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadService;

/**
 * Wires the YouTube connection classes to WordPress. Contains no logic worth testing without WordPress.
 */
final class WordPressFactory {

	public const CALLBACK_ACTION = 'wp_scatter_elsewhere_youtube_callback';
	public const UPLOAD_HOOK     = 'wp_scatter_elsewhere_process_upload';

	private const TOKEN_TRANSIENT = 'wp_scatter_elsewhere_youtube_access_token';
	private const STATE_TRANSIENT = 'wp_scatter_elsewhere_oauth_state_';

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

	public static function uploadSettings(): UploadSettings {
		return new UploadSettings(
			static fn(): mixed => get_option( UploadSettings::optionKey(), false ),
			static function ( array $value ): void {
				update_option( UploadSettings::optionKey(), $value, false );
			}
		);
	}

	public static function uploadService(): UploadService {
		$settings = self::settings();

		return new UploadService(
			new UploadJobStore(
				static fn(): mixed => get_option( UploadJobStore::optionKey(), false ),
				static function ( array $value ): void {
					update_option( UploadJobStore::optionKey(), $value, false );
				}
			),
			new ResumableUploader(
				self::uploadHttp(),
				self::accessTokenProvider( $settings ),
				static function ( string $path, int $offset, int $length ): string|false {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
					$handle = fopen( $path, 'rb' );
					if ( false === $handle ) {
						return false;
					}

					$data = 0 === fseek( $handle, $offset ) ? fread( $handle, $length ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

					return $data;
				},
				static fn(): int => time()
			),
			self::uploadSettings(),
			PublicationFactory::store(),
			self::subtitleService(),
			static function ( int $when, string $jobId ): void {
				// Refused without effect when the same run is already scheduled.
				wp_schedule_single_event( $when, self::UPLOAD_HOOK, [ $jobId ] );
			},
			static fn(): int => time(),
			static fn(): string => 'j' . bin2hex( random_bytes( 6 ) )
		);
	}

	public static function subtitleService(): SubtitleService {
		return new SubtitleService(
			new CaptionClient( self::uploadHttp(), self::accessTokenProvider( self::settings() ), static fn(): string => 'wpse' . bin2hex( random_bytes( 12 ) ) ),
			new SubtitleConverter(),
			self::uploadSettings(),
			static function ( string $path ): string|false {
				return is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		);
	}

	public static function videoUpdater(): VideoUpdater {
		return new VideoUpdater( self::uploadHttp(), self::accessTokenProvider( self::settings() ) );
	}

	/**
	 * @return \Closure(string, string, array<string, string>, string): array{status: int, headers: array<string, string>, body: string}
	 */
	private static function uploadHttp(): \Closure {
		return static function ( string $method, string $url, array $headers, string $body ): array {
			$response = wp_remote_request(
				$url,
				[
					'method'      => $method,
					'headers'     => $headers,
					'body'        => $body,
					'timeout'     => 120,
					// A 308 answer means "resume incomplete", not a redirection.
					'redirection' => 0,
				]
			);

			if ( is_wp_error( $response ) ) {
				throw new RuntimeException( $response->get_error_message() );
			}

			$responseHeaders = [];
			foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
				$responseHeaders[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
			}

			return [
				'status'  => (int) wp_remote_retrieve_response_code( $response ),
				'headers' => $responseHeaders,
				'body'    => (string) wp_remote_retrieve_body( $response ),
			];
		};
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
