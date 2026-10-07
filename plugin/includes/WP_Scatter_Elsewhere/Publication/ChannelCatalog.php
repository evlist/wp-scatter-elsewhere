<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Publication;

use Closure;
use WP_Scatter_Elsewhere\Text\Folding;
use WP_Scatter_Elsewhere\YouTube\CatalogVideo;

/**
 * The videos of the connected channel, kept for a while, searchable, with the video of the blog each one is linked to.
 */
final class ChannelCatalog {

	/** Seconds the list is kept. */
	public const TTL = 3600;

	/**
	 * @var Closure(int): CatalogVideo[]
	 */
	private Closure $read;

	/**
	 * @var Closure(): mixed
	 */
	private Closure $cacheGet;

	/**
	 * @var Closure(array<string, mixed>, int): void
	 */
	private Closure $cacheSet;

	/**
	 * @var Closure(): int
	 */
	private Closure $clock;

	private LinkIndex $links;

	private int $limit;

	/**
	 * @param Closure(int): CatalogVideo[]               $read     Reads the uploads of the channel, at most the given number.
	 * @param Closure(): mixed                           $cacheGet Returns the cached value, or false.
	 * @param Closure(array<string, mixed>, int): void   $cacheSet Caches a value for a number of seconds.
	 * @param Closure(): int                             $clock    Current Unix time.
	 * @param int                                        $limit    Safety limit on the number of videos read.
	 */
	public function __construct( Closure $read, Closure $cacheGet, Closure $cacheSet, Closure $clock, LinkIndex $links, int $limit = 500 ) {
		$this->read     = $read;
		$this->cacheGet = $cacheGet;
		$this->cacheSet = $cacheSet;
		$this->clock    = $clock;
		$this->links    = $links;
		$this->limit    = $limit;
	}

	/**
	 * @param bool   $refresh      Read the channel again even if the list is cached.
	 * @param string $search       Words that must all be in the title, ignoring case and accents.
	 * @param bool   $unlinkedOnly Leave out the videos that are linked to a video of the blog.
	 * @param int    $max          Maximum number of videos returned, 0 for all of them.
	 * @return array{videos: array<int, array{video: CatalogVideo, linked_to: ?array{post_id: int, video_id: string}}>, fetched_at: int, total: int}
	 * @throws \WP_Scatter_Elsewhere\YouTube\ChannelCatalogException When the channel cannot be read.
	 */
	public function list( bool $refresh = false, string $search = '', bool $unlinkedOnly = false, int $max = 0 ): array {
		$cached  = $refresh ? false : ( $this->cacheGet )();
		$entries = $this->fromCache( $cached );

		if ( null === $entries ) {
			$videos  = ( $this->read )( $this->limit );
			$entries = [ 'fetched_at' => ( $this->clock )(), 'videos' => $videos ];
			( $this->cacheSet )(
				[
					'fetched_at' => $entries['fetched_at'],
					'videos'     => array_map( static fn( CatalogVideo $video ): array => $video->toArray(), $videos ),
				],
				self::TTL
			);
		}

		$links = $this->links->map();
		$rows  = [];

		foreach ( $entries['videos'] as $video ) {
			$linkedTo = $links[ $video->id ] ?? null;

			if ( ( $unlinkedOnly && null !== $linkedTo ) || ! Folding::matches( $video->title, $search ) ) {
				continue;
			}

			$rows[] = [ 'video' => $video, 'linked_to' => $linkedTo ];
		}

		return [
			'videos'     => $max > 0 ? array_slice( $rows, 0, $max ) : $rows,
			'fetched_at' => $entries['fetched_at'],
			'total'      => count( $rows ),
		];
	}

	/**
	 * @param mixed $cached
	 * @return array{fetched_at: int, videos: CatalogVideo[]}|null
	 */
	private function fromCache( mixed $cached ): ?array {
		if ( ! is_array( $cached ) || ! isset( $cached['fetched_at'], $cached['videos'] ) || ! is_array( $cached['videos'] ) ) {
			return null;
		}

		$videos = [];
		foreach ( $cached['videos'] as $data ) {
			$video = is_array( $data ) ? CatalogVideo::fromArray( $data ) : null;
			if ( null !== $video ) {
				$videos[] = $video;
			}
		}

		return [ 'fetched_at' => (int) $cached['fetched_at'], 'videos' => $videos ];
	}
}
