<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Metadata;

use DateTimeImmutable;

/**
 * Post elements available to title and description templates.
 *
 * Holds raw values (the title and excerpt may still contain HTML); the renderer
 * converts them to plain text.
 */
final class PostData {

	/**
	 * @param array<string, string[]> $terms             Term names keyed by taxonomy.
	 * @param ?string                 $featuredImagePath Path of the original file of the featured image, if any.
	 * @param array<string, array<int, array{slug: string, ancestors: string[]}>> $termDetails Slugs of the terms of the post by taxonomy, with the slugs of their ancestors.
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $excerpt,
		public readonly string $permalink,
		public readonly DateTimeImmutable $date,
		public readonly string $author,
		public readonly array $terms = [],
		public readonly ?string $featuredImagePath = null,
		public readonly array $termDetails = [],
		private ?\Closure $contentProvider = null
	) {
	}

	private ?string $contentCache = null;

	/**
	 * The content of the post as HTML (blocks rendered), read only when a template needs it.
	 */
	public function content(): string {
		if ( null === $this->contentCache ) {
			$this->contentCache = null === $this->contentProvider ? '' : (string) ( $this->contentProvider )();
		}

		return $this->contentCache;
	}
}
