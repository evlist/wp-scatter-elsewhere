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
		$playlists = [];
		$keywords  = [];

		foreach ( $rules as $rule ) {
			if ( ! $this->applies( $rule, $post ) ) {
				continue;
			}

			if ( '' !== $rule->playlistId ) {
				$playlists[ $rule->playlistId ] = $rule->playlistId;
			}

			if ( '' !== $rule->keyword ) {
				$keywords[ mb_strtolower( $rule->keyword, 'UTF-8' ) ] ??= $rule->keyword;
			}
		}

		return new RuleMatch( array_values( $playlists ), array_values( $keywords ) );
	}

	/**
	 * A rule applies to a post that has its term, or, with "include sub-terms", a descendant of it.
	 */
	private function applies( TermRule $rule, PostData $post ): bool {
		foreach ( $post->termDetails[ $rule->taxonomy ] ?? [] as $term ) {
			if ( $term['slug'] === $rule->term ) {
				return true;
			}

			if ( $rule->includeChildren && in_array( $rule->term, $term['ancestors'], true ) ) {
				return true;
			}
		}

		return false;
	}
}
