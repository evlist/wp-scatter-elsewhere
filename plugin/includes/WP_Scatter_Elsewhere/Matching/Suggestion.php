<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Matching;

use WP_Scatter_Elsewhere\YouTube\CatalogVideo;

/**
 * A video of the channel proposed for a post.
 */
final class Suggestion {

	public const HIGH       = 'high';
	public const SUGGESTION = 'suggestion';

	/**
	 * @param string[] $reasons Codes: permalink, title, similar.
	 */
	public function __construct(
		public readonly CatalogVideo $video,
		public readonly string $confidence,
		public readonly array $reasons,
		public readonly int $score
	) {
	}
}
