<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Matching;

use Closure;
use WP_Scatter_Elsewhere\Publication\LinkException;

/**
 * Links the videos that the administrator ticked. The page is never trusted: a YouTube video that is
 * ticked twice is linked to neither, and every link goes through the checks of the link service.
 */
final class BulkApplier {

	/**
	 * @var Closure(int, string, string): void
	 */
	private Closure $link;

	/**
	 * @param Closure(int, string, string): void $link Links a video of a post to a YouTube video; throws LinkException.
	 */
	public function __construct( Closure $link ) {
		$this->link = $link;
	}

	/**
	 * @param array<int, array{post: int, video: string, youtube: string}> $items
	 * @return array<int, array{post: int, video: string, youtube: string, linked: bool, message: string}> In the order of the items.
	 */
	public function apply( array $items ): array {
		$counts = [];
		foreach ( $items as $item ) {
			$counts[ $item['youtube'] ] = ( $counts[ $item['youtube'] ] ?? 0 ) + 1;
		}

		$outcomes = [];
		foreach ( $items as $item ) {
			if ( $counts[ $item['youtube'] ] > 1 ) {
				$outcomes[] = $item + [ 'linked' => false, 'message' => __( 'This YouTube video is ticked for several videos of the blog.', 'wp-scatter-elsewhere' ) ];
				continue;
			}

			try {
				( $this->link )( $item['post'], $item['video'], $item['youtube'] );
				$outcomes[] = $item + [ 'linked' => true, 'message' => '' ];
			} catch ( LinkException $e ) {
				$outcomes[] = $item + [ 'linked' => false, 'message' => $e->getMessage() ];
			}
		}

		return $outcomes;
	}
}
