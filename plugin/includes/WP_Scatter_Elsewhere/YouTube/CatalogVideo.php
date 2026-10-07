<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

/**
 * A video of the connected channel, as listed by the catalogue.
 */
final class CatalogVideo {

	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $publishedAt,
		public readonly string $privacy,
		public readonly string $description,
		public readonly ?string $recordingDate,
		public readonly ?string $thumbnail
	) {
	}

	/**
	 * @return array<string, string|null>
	 */
	public function toArray(): array {
		return [
			'id'             => $this->id,
			'title'          => $this->title,
			'published_at'   => $this->publishedAt,
			'privacy'        => $this->privacy,
			'description'    => $this->description,
			'recording_date' => $this->recordingDate,
			'thumbnail'      => $this->thumbnail,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): ?self {
		$id = (string) ( $data['id'] ?? '' );
		if ( '' === $id ) {
			return null;
		}

		return new self(
			$id,
			(string) ( $data['title'] ?? '' ),
			(string) ( $data['published_at'] ?? '' ),
			(string) ( $data['privacy'] ?? '' ),
			(string) ( $data['description'] ?? '' ),
			isset( $data['recording_date'] ) ? (string) $data['recording_date'] : null,
			isset( $data['thumbnail'] ) ? (string) $data['thumbnail'] : null
		);
	}
}
