<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Matching;

use Closure;
use RuntimeException;
use WP_Scatter_Elsewhere\YouTube\CatalogVideo;

/**
 * Examines the posts a few at a time, so that a long scan fits in many short requests. The caller keeps
 * the cursor; the list of posts is the same at each step.
 */
final class BulkScan {

	private BulkLinker $linker;

	private VideoMatcher $matcher;

	public function __construct( BulkLinker $linker, VideoMatcher $matcher ) {
		$this->linker  = $linker;
		$this->matcher = $matcher;
	}

	/**
	 * @param int[]                                                                                             $postIds
	 * @param Closure(int): array{facts: PostFacts, videos: string[], linked: array<string, bool>}              $examine Reads a post; throws RuntimeException when it cannot.
	 * @param CatalogVideo[]                                                                                    $videos  Videos of the channel that are not linked.
	 * @return array{rows: array<int, array{post: int, video: string, decision: string, note: string, linked: bool, candidates: Suggestion[], best: ?Suggestion}>, errors: array<int, array{post: int, message: string}>, next: ?int, total: int}
	 */
	public function chunk( array $postIds, int $cursor, int $size, Closure $examine, array $videos, string $minConfidence ): array {
		$entries = [];
		$errors  = [];
		$slice   = array_slice( $postIds, max( 0, $cursor ), max( 1, $size ) );

		foreach ( $slice as $postId ) {
			try {
				$post = $examine( $postId );
			} catch ( RuntimeException $e ) {
				$errors[] = [ 'post' => $postId, 'message' => $e->getMessage() ];
				continue;
			}

			foreach ( $post['videos'] as $videoId ) {
				$entries[] = new BulkEntry( $postId, $videoId, $post['facts'], ! empty( $post['linked'][ $videoId ] ) );
			}
		}

		$rows = [];
		foreach ( $this->linker->plan( $entries, $videos, $minConfidence ) as $decision ) {
			$entry  = $decision->entry;
			$rows[] = [
				'post'       => $entry->postId,
				'video'      => $entry->videoId,
				'decision'   => $decision->decision,
				'note'       => $decision->note,
				'linked'     => $entry->linked,
				'candidates' => $entry->linked ? [] : $this->matcher->match( $entry->post, $videos ),
				'best'       => $decision->suggestion,
			];
		}

		$next = $cursor + count( $slice );

		return [ 'rows' => $rows, 'errors' => $errors, 'next' => $next < count( $postIds ) ? $next : null, 'total' => count( $postIds ) ];
	}
}
