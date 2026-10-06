<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Publication;

/**
 * Wires the publications to WordPress post meta. Contains no logic worth testing without WordPress.
 */
final class WordPressFactory {

	public static function store(): PublicationStore {
		return new PublicationStore(
			static fn( int $postId ): mixed => get_post_meta( $postId, PublicationStore::META_KEY, true ),
			static function ( int $postId, array $value ): void {
				update_post_meta( $postId, PublicationStore::META_KEY, $value );
			}
		);
	}

	public static function refresher(): PublicationRefresher {
		return new PublicationRefresher( \WP_Scatter_Elsewhere\YouTube\WordPressFactory::videoInspector(), self::store() );
	}

	/**
	 * The YouTube address of a video of a post, or null.
	 */
	public static function youtubeUrl( ?int $postId, ?string $videoId, bool $includePrivate ): ?string {
		$postId ??= (int) get_the_ID();
		if ( $postId <= 0 ) {
			return null;
		}

		$publication = PublicationLinks::select( self::store()->forPost( $postId ), $videoId, $includePrivate );

		return null === $publication ? null : $publication->url();
	}

	/**
	 * Shortcode [scatter_elsewhere_youtube_link post="" video="" text=""].
	 *
	 * @param array<string, string>|string $attributes
	 */
	public static function shortcode( array|string $attributes ): string {
		$attributes = shortcode_atts(
			[
				'post'  => '',
				'video' => '',
				'text'  => '',
			],
			is_array( $attributes ) ? $attributes : [],
			'scatter_elsewhere_youtube_link'
		);

		$url = self::youtubeUrl(
			'' === $attributes['post'] ? null : (int) $attributes['post'],
			'' === $attributes['video'] ? null : $attributes['video'],
			false
		);

		if ( null === $url ) {
			return '';
		}

		$text = '' === $attributes['text'] ? __( 'Watch on YouTube', 'wp-scatter-elsewhere' ) : $attributes['text'];

		return sprintf( '<a class="scatter-elsewhere-youtube-link" href="%s" rel="noopener noreferrer">%s</a>', esc_url( $url ), esc_html( $text ) );
	}
}
