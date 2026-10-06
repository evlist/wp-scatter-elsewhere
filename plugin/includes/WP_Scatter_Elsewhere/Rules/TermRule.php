<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Rules;

/**
 * A rule of the table: a term, with an optional playlist and an optional keyword.
 */
final class TermRule {

	public function __construct(
		public readonly string $taxonomy,
		public readonly string $term,
		public readonly string $playlistId,
		public readonly string $keyword,
		public readonly bool $includeChildren = false
	) {
	}

	/**
	 * @return array{taxonomy: string, term: string, playlist_id: string, keyword: string, include_children: bool}
	 */
	public function toArray(): array {
		return [
			'taxonomy'         => $this->taxonomy,
			'term'             => $this->term,
			'playlist_id'      => $this->playlistId,
			'keyword'          => $this->keyword,
			'include_children' => $this->includeChildren,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): ?self {
		$taxonomy = trim( (string) ( $data['taxonomy'] ?? '' ) );
		$term     = trim( (string) ( $data['term'] ?? '' ) );

		if ( '' === $taxonomy || '' === $term ) {
			return null;
		}

		return new self(
			$taxonomy,
			$term,
			trim( (string) ( $data['playlist_id'] ?? '' ) ),
			trim( (string) ( $data['keyword'] ?? '' ) ),
			(bool) ( $data['include_children'] ?? false )
		);
	}
}
