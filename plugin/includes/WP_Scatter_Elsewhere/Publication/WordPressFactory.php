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

	/**
	 * Links of the blog: the publications recorded on the posts and the finished uploads that predate them.
	 */
	public static function linkIndex(): LinkIndex {
		return new LinkIndex(
			static function (): iterable {
				$store = self::store();
				$ids   = get_posts(
					[
						'post_type'      => 'any',
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'meta_key'       => PublicationStore::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'no_found_rows'  => true,
					]
				);

				foreach ( $ids as $postId ) {
					foreach ( $store->forPost( (int) $postId ) as $publication ) {
						yield [ 'post_id' => (int) $postId, 'video_id' => $publication->videoId, 'youtube_id' => $publication->youtubeId ];
					}
				}

				foreach ( \WP_Scatter_Elsewhere\YouTube\WordPressFactory::uploadService()->jobs() as $job ) {
					if ( 'done' === $job->status() && ! $job->isUnlinked() && null !== $job->youtubeId() ) {
						yield [ 'post_id' => $job->postId(), 'video_id' => $job->videoId(), 'youtube_id' => $job->youtubeId() ];
					}
				}
			}
		);
	}

	/**
	 * The videos of the channel, kept in a transient for an hour.
	 */
	public static function channelCatalog(): ChannelCatalog {
		$key    = 'wp_scatter_elsewhere_channel_videos';
		$reader = \WP_Scatter_Elsewhere\YouTube\WordPressFactory::channelVideoReader();

		return new ChannelCatalog(
			static fn( int $limit ): array => $reader->read( $limit ),
			static fn(): mixed => get_transient( $key ),
			static function ( array $value, int $ttl ) use ( $key ): void {
				set_transient( $key, $value, $ttl );
			},
			static fn(): int => time(),
			self::linkIndex()
		);
	}

	public static function linkService(): LinkService {
		$youtube = \WP_Scatter_Elsewhere\YouTube\WordPressFactory::class;
		$uploads = $youtube::uploadService();
		$index   = self::linkIndex();

		return new LinkService(
			$youtube::videoInspector(),
			self::store(),
			static fn( int $postId, string $videoId ): bool => $uploads->unlink( $postId, $videoId ),
			static fn( string $youtubeId ): ?array => $index->find( $youtubeId ),
			static fn(): string => $youtube::settings()->channelId(),
			static fn(): int => time()
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
