<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Rules;

/**
 * The playlists and keywords that the rules give to a post.
 */
final class RuleMatch {

	/**
	 * @param string[] $playlists Playlist IDs, without duplicates.
	 * @param string[] $keywords  Keywords, without duplicates.
	 */
	public function __construct(
		public readonly array $playlists,
		public readonly array $keywords
	) {
	}
}
