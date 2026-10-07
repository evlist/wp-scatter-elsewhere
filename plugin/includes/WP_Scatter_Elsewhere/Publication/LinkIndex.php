<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Publication;

use Closure;

/**
 * Tells which video of the blog a YouTube video is linked to.
 */
final class LinkIndex {

	/**
	 * @var Closure(): iterable<array{post_id: int, video_id: string, youtube_id: string}>
	 */
	private Closure $records;

	/**
	 * @param Closure(): iterable<array{post_id: int, video_id: string, youtube_id: string}> $records Every link of the blog.
	 */
	public function __construct( Closure $records ) {
		$this->records = $records;
	}

	/**
	 * Every YouTube video that is linked, with the video of the blog it is linked to.
	 *
	 * @return array<string, array{post_id: int, video_id: string}>
	 */
	public function map(): array {
		$map = [];
		foreach ( ( $this->records )() as $record ) {
			$map[ $record['youtube_id'] ] ??= [ 'post_id' => $record['post_id'], 'video_id' => $record['video_id'] ];
		}

		return $map;
	}

	/**
	 * @return array{post_id: int, video_id: string}|null
	 */
	public function find( string $youtubeId ): ?array {
		foreach ( ( $this->records )() as $record ) {
			if ( $record['youtube_id'] === $youtubeId ) {
				return [ 'post_id' => $record['post_id'], 'video_id' => $record['video_id'] ];
			}
		}

		return null;
	}
}
