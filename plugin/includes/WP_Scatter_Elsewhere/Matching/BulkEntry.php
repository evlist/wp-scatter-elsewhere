<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Matching;

/**
 * A video of a post of the blog, examined by the bulk linking.
 */
final class BulkEntry {

	public function __construct(
		public readonly int $postId,
		public readonly string $videoId,
		public readonly PostFacts $post,
		public readonly bool $linked
	) {
	}
}
