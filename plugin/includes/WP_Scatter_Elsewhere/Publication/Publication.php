<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Publication;

/**
 * The YouTube video a video of a post was uploaded to (or linked with).
 */
final class Publication {

	private const WATCH_URL = 'https://www.youtube.com/watch?v=';

	/**
	 * @param ?string $privacy As requested at upload time; null when unknown (manual link).
	 * @param ?string $jobId   Null for a manual link.
	 */
	public function __construct(
		public readonly string $videoId,
		public readonly string $youtubeId,
		public readonly ?string $privacy,
		public readonly int $publishedAt,
		public readonly ?string $jobId
	) {
	}

	/**
	 * Whether a string has the shape of a YouTube video id.
	 */
	public static function isValidYouTubeId( string $id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{11}$/', $id );
	}

	public function url(): string {
		return self::WATCH_URL . $this->youtubeId;
	}

	public function isPrivate(): bool {
		return 'private' === $this->privacy;
	}

	/**
	 * @return array{youtube_id: string, privacy: ?string, published_at: int, job_id: ?string}
	 */
	public function toArray(): array {
		return [
			'youtube_id'   => $this->youtubeId,
			'privacy'      => $this->privacy,
			'published_at' => $this->publishedAt,
			'job_id'       => $this->jobId,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( string $videoId, array $data ): ?self {
		$youtubeId = (string) ( $data['youtube_id'] ?? '' );
		if ( '' === $youtubeId ) {
			return null;
		}

		return new self(
			$videoId,
			$youtubeId,
			isset( $data['privacy'] ) ? (string) $data['privacy'] : null,
			(int) ( $data['published_at'] ?? 0 ),
			isset( $data['job_id'] ) ? (string) $data['job_id'] : null
		);
	}
}
