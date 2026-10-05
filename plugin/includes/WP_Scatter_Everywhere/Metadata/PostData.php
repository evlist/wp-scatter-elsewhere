<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\Metadata;

use DateTimeImmutable;

/**
 * Post elements available to title and description templates.
 *
 * Holds raw values (the title and excerpt may still contain HTML); the renderer
 * converts them to plain text.
 */
final class PostData {

	/**
	 * @param array<string, string[]> $terms Term names keyed by taxonomy.
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $excerpt,
		public readonly string $permalink,
		public readonly DateTimeImmutable $date,
		public readonly string $author,
		public readonly array $terms = []
	) {
	}
}
