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

	public static function scan(): BulkScan {
		$matcher = new VideoMatcher();

		return new BulkScan( new BulkLinker( $matcher ), $matcher );
	}

	public static function applier(): BulkApplier {
		$service = PublicationFactory::linkService();

		return new BulkApplier(
			static function ( int $postId, string $videoId, string $youtubeId ) use ( $service ): void {
				$service->link( $postId, $videoId, $youtubeId );
			}
		);
	}

	public static function runLog(): LinkRunLog {
		return new LinkRunLog(
			static fn(): mixed => get_option( 'wp_scatter_elsewhere_link_runs', false ),
			static function ( array $runs ): void {
				update_option( 'wp_scatter_elsewhere_link_runs', $runs, false );
			},
			static fn(): int => time(),
			static fn(): string => 'r' . bin2hex( random_bytes( 6 ) )
		);
	}

	/**
	 * The published posts that mention a video, oldest first.
	 *
	 * @param int[]|null $only         Only these posts.
	 * @param ?string    $since        Only the posts dated on or after this date (YYYY-MM-DD).
	 * @param int        $limit        Maximum number of posts, 0 for all of them.
	 * @param bool       $unlinkedOnly Leave out the posts that already have a linked video.
	 * @param string     $needle       Text the content must contain ("video" by default; "gpx" to find the posts with a GPX file).
	 * @return int[]
	 */
	public static function postsWithVideo( ?array $only = null, ?string $since = null, int $limit = 0, bool $unlinkedOnly = false, string $needle = 'video' ): array {
		$query = [
			'post_type'      => 'any',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];

		if ( null !== $only ) {
			$query['post__in'] = $only;
		}

		if ( null !== $since && '' !== $since ) {
			$query['date_query'] = [ [ 'after' => $since, 'inclusive' => true ] ];
		}

		$linkedPosts = [];
		if ( $unlinkedOnly ) {
			foreach ( PublicationFactory::linkIndex()->map() as $link ) {
				$linkedPosts[ $link['post_id'] ] = true;
			}
		}

		$ids = [];
		foreach ( get_posts( $query ) as $postId ) {
			if ( isset( $linkedPosts[ (int) $postId ] ) || false === stripos( (string) get_post_field( 'post_content', (int) $postId ), $needle ) ) {
				continue;
			}

			$ids[] = (int) $postId;
			if ( $limit > 0 && count( $ids ) >= $limit ) {
				break;
			}
		}

		return $ids;
	}

	/**
	 * Reads a post for the scan: its facts, the videos of its page and which of them are linked.
	 *
	 * @return array{facts: PostFacts, videos: string[], linked: array<string, bool>}
	 * @throws \RuntimeException When the post or its page cannot be read.
	 */
	public static function examine( int $postId ): array {
		$facts = self::postFacts( $postId );
		if ( null === $facts ) {
			throw new \RuntimeException( __( 'This post does not exist.', 'wp-scatter-elsewhere' ) );
		}

		try {
			$detected = \WP_Scatter_Elsewhere\Detection\WordPressDetectorFactory::create()->detect( $postId );
		} catch ( \WP_Scatter_Elsewhere\Detection\DetectionException $e ) {
			throw new \RuntimeException( $e->getMessage() );
		}

		$uploads = \WP_Scatter_Elsewhere\YouTube\WordPressFactory::uploadService();
		$videos  = [];
		$linked  = [];
		foreach ( $detected as $video ) {
			$videos[]                = $video->id;
			$linked[ $video->id ] = null !== $uploads->publicationFor( $postId, $video->id );
		}

		return [ 'facts' => $facts, 'videos' => $videos, 'linked' => $linked ];
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
