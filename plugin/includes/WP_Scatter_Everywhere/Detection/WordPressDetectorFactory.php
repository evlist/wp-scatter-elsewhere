<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\Detection;

use RuntimeException;
use WP_Post;

/**
 * Wires the detector to WordPress. Contains no logic worth testing without WordPress.
 */
final class WordPressDetectorFactory {

	public static function create(): VideoDetector {
		$uploads = wp_get_upload_dir();
		$urls    = new UrlResolver();

		$fetcher = new PageFetcher(
			static function ( int $postId ): ?array {
				$post = get_post( $postId );
				if ( ! $post instanceof WP_Post ) {
					return null;
				}

				return [
					'permalink'          => (string) get_permalink( $post ),
					'status'             => $post->post_status,
					'password_protected' => '' !== $post->post_password,
					'viewable'           => is_post_type_viewable( $post->post_type ),
				];
			},
			static function ( string $url ): array {
				$response = wp_remote_get(
					$url,
					[
						'timeout'     => 15,
						'redirection' => 5,
						'cookies'     => [],
						/**
						 * Filters whether the loopback request verifies the TLS certificate.
						 * Relaxing it is only meant for local environments.
						 */
						'sslverify'   => (bool) apply_filters( 'wp_scatter_everywhere_loopback_sslverify', true ),
					]
				);

				if ( is_wp_error( $response ) ) {
					throw new RuntimeException( $response->get_error_message() );
				}

				return [
					'status' => (int) wp_remote_retrieve_response_code( $response ),
					'body'   => (string) wp_remote_retrieve_body( $response ),
				];
			}
		);

		$resolver = new LocalFileResolver(
			$uploads['baseurl'],
			$uploads['basedir'],
			static function ( string $url ): ?int {
				$id = attachment_url_to_postid( $url );

				return $id > 0 ? $id : null;
			}
		);

		return new RenderedPageDetector( $fetcher, new PageParser( $urls ), $resolver );
	}
}
