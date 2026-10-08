<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Update;

use Closure;
use InvalidArgumentException;
use WP_Scatter_Elsewhere\YouTube\PlaylistException;
use WP_Scatter_Elsewhere\YouTube\VideoMetadata;
use WP_Scatter_Elsewhere\YouTube\YouTubeConnectionException;

/**
 * Brings a video that is on YouTube in line with what its post gives now, for the fields that are asked for,
 * and reports the differences. Without $apply it only reads.
 */
final class BatchUpdater {

	/** Fields that are not compared: they are sent again whatever YouTube holds. */
	public const ACTIONS = [ 'thumbnail', 'subtitles' ];

	/** Fields of the video itself, in the order of the report. */
	public const FIELDS = [ 'title', 'description', 'language', 'license', 'recording_date', 'category', 'embeddable', 'public_stats', 'made_for_kids', 'privacy', 'keywords', 'playlists', 'thumbnail', 'subtitles' ];

	/** @var Closure(string): array<string, mixed> */
	private Closure $read;

	/** @var Closure(string, array<string, mixed>): string[] */
	private Closure $update;

	/** @var Closure(string, string): bool */
	private Closure $hasItem;

	/** @var Closure(string, string): void */
	private Closure $addItem;

	/** @var Closure(string, string): string[] */
	private Closure $itemIds;

	/** @var Closure(string): void */
	private Closure $removeItem;

	private VideoDiff $diff;

	/**
	 * @param Closure(string): array<string, mixed>                   $read       The video on YouTube (snippet, status, recordingDetails).
	 * @param Closure(string, array<string, mixed>): string[]         $update     Applies the changes; returns the keywords that did not fit.
	 * @param Closure(string, string): bool                           $hasItem    Whether a playlist holds a video.
	 * @param Closure(string, string): void                           $addItem    Puts a video in a playlist.
	 * @param Closure(string, string): string[]                       $itemIds    The items of a playlist that hold a video.
	 * @param Closure(string): void                                   $removeItem Deletes a playlist item.
	 */
	public function __construct( Closure $read, Closure $update, Closure $hasItem, Closure $addItem, Closure $itemIds, Closure $removeItem, VideoDiff $diff ) {
		$this->read       = $read;
		$this->update     = $update;
		$this->hasItem    = $hasItem;
		$this->addItem    = $addItem;
		$this->itemIds    = $itemIds;
		$this->removeItem = $removeItem;
		$this->diff       = $diff;
	}

	/**
	 * @param string[]                   $fields           Fields asked for, among self::FIELDS.
	 * @param string[]                   $managedKeywords  Keywords named by the rules.
	 * @param string[]                   $managedPlaylists Playlists named by the rules.
	 * @param array<string, Closure():string> $actions      What to do for "thumbnail" and "subtitles": returns a short description.
	 */
	public function process( string $youtubeId, VideoMetadata $desired, array $fields, string $keywordsMode, string $playlistsMode, array $managedKeywords, array $managedPlaylists, ?string $privacy, bool $apply, array $actions = [] ): VideoReport {
		$rows    = [];
		$cost    = 0;
		$errors  = 0;
		$stopped = false;

		try {
			$compared = array_intersect( $fields, VideoDiff::SINGLE );
			if ( [] !== $compared || in_array( 'keywords', $fields, true ) ) {
				$cost    += VideoReport::COST_READ;
				$result   = $this->diff->compare( $desired, ( $this->read )( $youtubeId ), $fields, $keywordsMode, $managedKeywords, $privacy );

				foreach ( $result['skipped'] as $field ) {
					$rows[] = [ 'field' => $field, 'current' => '', 'new' => '', 'action' => 'skipped: no value' ];
				}

				if ( [] !== $result['changes'] ) {
					$cost += VideoReport::COST_UPDATE;
					$state = $apply ? 'updated' : 'would update';

					if ( $apply ) {
						( $this->update )( $youtubeId, array_merge( [], ...array_map( static fn( Change $change ): array => $change->payload, $result['changes'] ) ) );
					}

					foreach ( $result['changes'] as $change ) {
						$rows[] = [ 'field' => $change->field, 'current' => $change->current, 'new' => $change->new, 'action' => $state ];
					}
				}
			}
		} catch ( InvalidArgumentException | YouTubeConnectionException $e ) {
			++$errors;
			$rows[] = [ 'field' => 'video', 'current' => '', 'new' => $e->getMessage(), 'action' => 'error' ];
		}

		if ( in_array( 'playlists', $fields, true ) ) {
			[ $playlistRows, $playlistCost, $playlistErrors, $stopped ] = $this->playlists( $youtubeId, $desired->playlists, $managedPlaylists, VideoDiff::MODE_SYNC === $playlistsMode, $apply );
			$rows   = array_merge( $rows, $playlistRows );
			$cost  += $playlistCost;
			$errors += $playlistErrors;
		}

		foreach ( self::ACTIONS as $field ) {
			if ( ! in_array( $field, $fields, true ) || ! isset( $actions[ $field ] ) || $stopped ) {
				continue;
			}

			$cost += 'thumbnail' === $field ? VideoReport::COST_THUMBNAIL : VideoReport::COST_SUBTITLES;

			try {
				$detail = $apply ? ( $actions[ $field ] )() : '';
				$rows[] = [ 'field' => $field, 'current' => '', 'new' => $detail, 'action' => $apply ? 'sent' : 'would send' ];
			} catch ( InvalidArgumentException | YouTubeConnectionException $e ) {
				++$errors;
				$rows[] = [ 'field' => $field, 'current' => '', 'new' => $e->getMessage(), 'action' => 'error' ];
			}
		}

		return new VideoReport( $rows, $cost, $errors, $stopped );
	}

	/**
	 * @param string[] $wanted
	 * @param string[] $managed
	 * @return array{0: array<int, array{field: string, current: string, new: string, action: string}>, 1: int, 2: int, 3: bool}
	 */
	private function playlists( string $youtubeId, array $wanted, array $managed, bool $sync, bool $apply ): array {
		$rows   = [];
		$cost   = 0;
		$errors = 0;

		foreach ( $wanted as $playlist ) {
			try {
				$cost += VideoReport::COST_READ;
				if ( ( $this->hasItem )( $playlist, $youtubeId ) ) {
					continue;
				}

				$cost += VideoReport::COST_PLAYLIST_ITEM;
				if ( $apply ) {
					( $this->addItem )( $playlist, $youtubeId );
				}
				$rows[] = [ 'field' => 'playlists', 'current' => '', 'new' => $playlist, 'action' => $apply ? 'added' : 'would add' ];
			} catch ( PlaylistException $e ) {
				++$errors;
				$rows[] = [ 'field' => 'playlists', 'current' => $playlist, 'new' => $e->getMessage(), 'action' => 'error' ];
				if ( $e->isQuota() ) {
					return [ $rows, $cost, $errors, true ];
				}
			}
		}

		if ( $sync ) {
			foreach ( array_diff( $managed, $wanted ) as $playlist ) {
				try {
					$cost += VideoReport::COST_READ;
					$ids   = ( $this->itemIds )( $playlist, $youtubeId );
					if ( [] === $ids ) {
						continue;
					}

					$cost += VideoReport::COST_PLAYLIST_ITEM * count( $ids );
					if ( $apply ) {
						foreach ( $ids as $id ) {
							( $this->removeItem )( $id );
						}
					}
					$rows[] = [ 'field' => 'playlists', 'current' => $playlist, 'new' => '', 'action' => $apply ? 'removed' : 'would remove' ];
				} catch ( PlaylistException $e ) {
					++$errors;
					$rows[] = [ 'field' => 'playlists', 'current' => $playlist, 'new' => $e->getMessage(), 'action' => 'error' ];
					if ( $e->isQuota() ) {
						return [ $rows, $cost, $errors, true ];
					}
				}
			}
		}

		return [ $rows, $cost, $errors, false ];
	}
}
