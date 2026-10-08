<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Rules;

use WP_Scatter_Elsewhere\Metadata\PostData;

/**
 * Applies the rules to the terms of a post.
 */
final class RuleMatcher {

	/**
	 * @param TermRule[] $rules In the order of the table.
	 */
	public function match( array $rules, PostData $post ): RuleMatch {
		$playlists   = [];
		$keywords    = [];
		$category    = null;
		$bestDepth   = -1;

		foreach ( $rules as $rule ) {
			$depth = $this->depth( $rule, $post );
			if ( null === $depth ) {
				continue;
			}

			// The most specific term decides the category; on a tie the first row of the table wins.
			if ( '' !== $rule->categoryId && $depth > $bestDepth ) {
				$category  = $rule->categoryId;
				$bestDepth = $depth;
			}

			if ( '' !== $rule->playlistId ) {
				$playlists[ $rule->playlistId ] = $rule->playlistId;
			}

			if ( '' !== $rule->keyword ) {
				$keywords[ mb_strtolower( $rule->keyword, 'UTF-8' ) ] ??= $rule->keyword;
			}
		}

		return new RuleMatch( array_values( $playlists ), array_values( $keywords ), $category );
	}

	/**
	 * A rule applies to a post that has its term, or, with "include sub-terms", a descendant of it.
	 *
	 * @return int|null The depth of the term of the rule in its hierarchy (0 for a top-level term), the greatest one
	 *                  when the post matches in several ways, or null when the rule does not apply.
	 */
	private function depth( TermRule $rule, PostData $post ): ?int {
		$depth = null;

		foreach ( $post->termDetails[ $rule->taxonomy ] ?? [] as $term ) {
			// The ancestors go from the parent up to the root.
			if ( $term['slug'] === $rule->term ) {
				$found = count( $term['ancestors'] );
			} elseif ( $rule->includeChildren && false !== ( $position = array_search( $rule->term, $term['ancestors'], true ) ) ) {
				$found = count( $term['ancestors'] ) - 1 - (int) $position;
			} else {
				continue;
			}

			$depth = null === $depth ? $found : max( $depth, $found );
		}

		return $depth;
	}
}
