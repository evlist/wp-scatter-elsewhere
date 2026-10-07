<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Matching;

use WP_Scatter_Elsewhere\Publication\WordPressFactory as PublicationFactory;

/**
 * Wires the suggestions to WordPress. Contains no logic worth testing without WordPress.
 */
final class WordPressFactory {

	public static function suggester(): VideoSuggester {
		return new VideoSuggester( PublicationFactory::channelCatalog(), new VideoMatcher() );
	}

	public static function postFacts( int $postId ): ?PostFacts {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return new PostFacts(
			html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
			(string) get_permalink( $post ),
			(int) get_post_timestamp( $post )
		);
	}

	/**
	 * A suggestion as sent to the editor and shown by WP-CLI.
	 *
	 * @return array{youtube_id: string, title: string, published_at: string, privacy: string, confidence: string, reasons: string[]}
	 */
	public static function describe( Suggestion $suggestion ): array {
		return [
			'youtube_id'   => $suggestion->video->id,
			'title'        => $suggestion->video->title,
			'published_at' => $suggestion->video->publishedAt,
			'privacy'      => $suggestion->video->privacy,
			'confidence'   => $suggestion->confidence,
			'reasons'      => $suggestion->reasons,
		];
	}

	public static function reasonLabel( string $reason ): string {
		switch ( $reason ) {
			case 'permalink':
				return __( 'address of the post found in the description', 'wp-scatter-elsewhere' );
			case 'title':
				return __( 'same title', 'wp-scatter-elsewhere' );
			default:
				return __( 'similar title and date', 'wp-scatter-elsewhere' );
		}
	}
}
