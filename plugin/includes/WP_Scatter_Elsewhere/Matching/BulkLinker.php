<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Matching;

use WP_Scatter_Elsewhere\YouTube\CatalogVideo;

/**
 * Pairs the videos of many posts with the videos of the channel and decides which pairs can be linked
 * without asking. A YouTube video wanted by two videos of the blog is left for a decision.
 */
final class BulkLinker {

	private VideoMatcher $matcher;

	public function __construct( VideoMatcher $matcher ) {
		$this->matcher = $matcher;
	}

	/**
	 * @param BulkEntry[]    $entries       Videos of posts, in the order of the report.
	 * @param CatalogVideo[] $videos        Videos of the channel that are not linked yet.
	 * @param string         $minConfidence Suggestion::HIGH or Suggestion::SUGGESTION: the confidence needed to link.
	 * @return BulkDecision[] One per entry, in the same order.
	 */
	public function plan( array $entries, array $videos, string $minConfidence = Suggestion::HIGH ): array {
		$best   = [];
		$claims = [];

		foreach ( $entries as $index => $entry ) {
			if ( $entry->linked ) {
				continue;
			}

			$found = $this->matcher->match( $entry->post, $videos );
			if ( [] !== $found ) {
				$best[ $index ] = $found[0];
				$claims[ $found[0]->video->id ][] = $index;
			}
		}

		$decisions = [];
		foreach ( $entries as $index => $entry ) {
			if ( $entry->linked ) {
				$decisions[] = new BulkDecision( $entry, BulkDecision::ALREADY_LINKED, null );
			} elseif ( ! isset( $best[ $index ] ) ) {
				$decisions[] = new BulkDecision( $entry, BulkDecision::NO_CANDIDATE, null );
			} elseif ( count( $claims[ $best[ $index ]->video->id ] ) > 1 ) {
				$decisions[] = new BulkDecision( $entry, BulkDecision::NEEDS_DECISION, $best[ $index ], __( 'claimed by several videos of the blog', 'wp-scatter-elsewhere' ) );
			} elseif ( $this->reaches( $best[ $index ]->confidence, $minConfidence ) ) {
				$decisions[] = new BulkDecision( $entry, BulkDecision::WOULD_LINK, $best[ $index ] );
			} else {
				$decisions[] = new BulkDecision( $entry, BulkDecision::NEEDS_DECISION, $best[ $index ], __( 'confidence too low', 'wp-scatter-elsewhere' ) );
			}
		}

		return $decisions;
	}

	private function reaches( string $confidence, string $required ): bool {
		return Suggestion::SUGGESTION === $required || Suggestion::HIGH === $confidence;
	}
}
