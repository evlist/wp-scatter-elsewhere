<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Update;

use InvalidArgumentException;
use WP_Scatter_Elsewhere\Detection\DetectionException;
use WP_Scatter_Elsewhere\Detection\WordPressDetectorFactory;
use WP_Scatter_Elsewhere\Metadata\WordPressFactory as MetadataFactory;
use WP_Scatter_Elsewhere\Publication\WordPressFactory as PublicationFactory;
use WP_Scatter_Elsewhere\Quota\QuotaMeter;
use WP_Scatter_Elsewhere\Rules\WordPressFactory as RulesFactory;
use WP_Scatter_Elsewhere\Thumbnails\WordPressFactory as ThumbnailFactory;
use WP_Scatter_Elsewhere\YouTube\WordPressFactory as YouTubeFactory;

/**
 * Wires the batch update to the YouTube clients. Contains no logic worth testing without WordPress.
 */
final class WordPressFactory {

	public const HOOK = 'wp_scatter_elsewhere_process_batch';

	public static function store(): BatchJobStore {
		return new BatchJobStore(
			static fn( string $key ): mixed => get_option( $key, false ),
			static function ( string $key, mixed $value ): void {
				update_option( $key, $value, false );
			},
			static function ( string $key ): void {
				delete_option( $key );
			}
		);
	}

	public static function runner(): BatchRunner {
		return new BatchRunner(
			self::store(),
			static fn( BatchPlan $plan, array $target ): VideoReport => self::processVideo( $plan, $target, true ),
			static fn(): int => time(),
			static function ( int $when, string $jobId ): void {
				// Refused without effect when the same run is already scheduled.
				wp_schedule_single_event( $when, self::HOOK, [ $jobId ] );
			},
			static function (): array {
				$meter = YouTubeFactory::quotaMeter();

				return [ 'exhausted' => QuotaMeter::LEVEL_EXHAUSTED === $meter->level(), 'remaining' => $meter->remaining(), 'reset' => $meter->nextReset() ];
			},
			static fn(): string => 'b' . bin2hex( random_bytes( 6 ) )
		);
	}

	/**
	 * What a video costs at worst, to stop before the limit rather than in the middle of a video.
	 *
	 * @param array{post: int, video: string, youtube: string} $target
	 */
	public static function worstCase( BatchPlan $plan, array $target ): int {
		$post = get_post( $target['post'] );
		$cost = 52;

		if ( $post instanceof \WP_Post && in_array( 'playlists', $plan->fields, true ) ) {
			$desired = MetadataFactory::videoMetadataBuilder()->build( MetadataFactory::postData( $post ) );
			$cost   += 51 * ( count( $desired->playlists ) + count( ManagedSets::playlists( RulesFactory::settings()->rules() ) ) );
		}

		return $cost + ( in_array( 'thumbnail', $plan->fields, true ) ? 50 : 0 ) + ( in_array( 'subtitles', $plan->fields, true ) ? 400 : 0 );
	}

	/**
	 * Applies the plan to a video (or only reports with $apply false), reading its current state.
	 *
	 * @param array{post: int, video: string, youtube: string} $target
	 */
	public static function processVideo( BatchPlan $plan, array $target, bool $apply ): VideoReport {
		$post = get_post( $target['post'] );
		if ( ! $post instanceof \WP_Post ) {
			return new VideoReport( [ [ 'field' => 'video', 'current' => '', 'new' => __( 'This post does not exist anymore.', 'wp-scatter-elsewhere' ), 'action' => 'error' ] ], 0, 1, false );
		}

		$rules   = RulesFactory::settings()->rules();
		$desired = MetadataFactory::videoMetadataBuilder()->build( MetadataFactory::postData( $post ) );

		return self::batchUpdater()->process(
			$target['youtube'],
			$desired,
			$plan->fields,
			$plan->keywords,
			$plan->playlists,
			ManagedSets::keywords( $rules ),
			ManagedSets::playlists( $rules ),
			$plan->privacy,
			$apply,
			self::actions( $plan->fields, $post, $target['video'], $target['youtube'] )
		);
	}

	/**
	 * The linked videos the plan selects, oldest post first.
	 *
	 * @return array<int, array{post: int, video: string, youtube: string}>
	 */
	public static function targets( BatchPlan $plan ): array {
		$since = null !== $plan->since ? (int) strtotime( $plan->since . ' 00:00:00' ) : null;
		$until = null !== $plan->until ? (int) strtotime( $plan->until . ' 23:59:59' ) : null;
		[ $taxonomy, $slug ] = array_pad( explode( ':', $plan->term, 2 ), 2, '' );

		$targets = [];
		foreach ( PublicationFactory::linkIndex()->map() as $youtube => $link ) {
			$postId = $link['post_id'];
			$post   = get_post( $postId );
			if ( ! $post instanceof \WP_Post || ( [] !== $plan->posts && ! in_array( $postId, $plan->posts, true ) ) ) {
				continue;
			}

			$time = (int) get_post_timestamp( $post );
			if ( ( null !== $since && $time < $since ) || ( null !== $until && $time > $until ) ) {
				continue;
			}

			if ( '' !== $slug && ! self::postHasTerm( $postId, $taxonomy, $slug ) ) {
				continue;
			}

			$targets[] = [ 'post' => $postId, 'video' => $link['video_id'], 'youtube' => (string) $youtube, 'time' => $time ];
		}

		usort( $targets, static fn( array $a, array $b ): int => $a['time'] <=> $b['time'] );

		return array_map(
			static fn( array $target ): array => [ 'post' => $target['post'], 'video' => $target['video'], 'youtube' => $target['youtube'] ],
			array_slice( $targets, 0, $plan->limit > 0 ? $plan->limit : null )
		);
	}

	/**
	 * Whether a post has a term or one of its descendants.
	 */
	private static function postHasTerm( int $postId, string $taxonomy, string $slug ): bool {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return false;
		}

		foreach ( wp_get_object_terms( $postId, $taxonomy ) as $postTerm ) {
			if ( $postTerm instanceof \WP_Term && ( $postTerm->term_id === $term->term_id || term_is_ancestor_of( $term->term_id, $postTerm->term_id, $taxonomy ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What to do for the fields that are sent again whatever YouTube holds.
	 *
	 * @param string[] $fields
	 * @return array<string, \Closure():string>
	 */
	private static function actions( array $fields, \WP_Post $post, string $videoId, string $youtubeId ): array {
		$actions = [];

		if ( in_array( 'thumbnail', $fields, true ) ) {
			$actions['thumbnail'] = static function () use ( $post, $youtubeId ): string {
				$image = MetadataFactory::postData( $post )->featuredImagePath;
				if ( null === $image ) {
					throw new InvalidArgumentException( __( 'This post has no featured image, or its file cannot be read.', 'wp-scatter-elsewhere' ) );
				}

				ThumbnailFactory::service()->send( $youtubeId, $image );

				return basename( $image );
			};
		}

		if ( in_array( 'subtitles', $fields, true ) ) {
			$actions['subtitles'] = static function () use ( $post, $videoId, $youtubeId ): string {
				$video = null;
				try {
					foreach ( WordPressDetectorFactory::create()->detect( $post->ID ) as $detected ) {
						if ( $detected->id === $videoId ) {
							$video = $detected;
						}
					}
				} catch ( DetectionException $e ) {
					throw new InvalidArgumentException( $e->getMessage() );
				}

				if ( null === $video ) {
					throw new InvalidArgumentException( __( 'No video with this ID in the page of the post.', 'wp-scatter-elsewhere' ) );
				}

				$tracks = [];
				foreach ( $video->subtitles as $track ) {
					if ( $track->isUsable() ) {
						$tracks[] = [ 'language' => (string) $track->language, 'path' => $track->file->path, 'name' => (string) $track->label ];
					}
				}

				if ( [] === $tracks ) {
					throw new InvalidArgumentException( __( 'No usable subtitle track in the page of this post.', 'wp-scatter-elsewhere' ) );
				}

				$result = YouTubeFactory::subtitleService()->sync( $youtubeId, $tracks, null );
				if ( $result->hasErrors() ) {
					throw new InvalidArgumentException( $result->errorSummary() );
				}

				return implode( ', ', array_keys( $result->actions ) );
			};
		}

		return $actions;
	}

	public static function batchUpdater(): BatchUpdater {
		$updater   = YouTubeFactory::videoUpdater();
		$playlists = YouTubeFactory::playlistClient();

		return new BatchUpdater(
			static fn( string $youtubeId ): array => $updater->snapshot( $youtubeId ),
			static fn( string $youtubeId, array $changes ): array => $updater->update( $youtubeId, $changes ),
			static fn( string $playlistId, string $youtubeId ): bool => $playlists->contains( $playlistId, $youtubeId ),
			static function ( string $playlistId, string $youtubeId ) use ( $playlists ): void {
				$playlists->add( $playlistId, $youtubeId );
			},
			static fn( string $playlistId, string $youtubeId ): array => $playlists->itemIds( $playlistId, $youtubeId ),
			static function ( string $itemId ) use ( $playlists ): void {
				$playlists->remove( $itemId );
			},
			new VideoDiff()
		);
	}
}
