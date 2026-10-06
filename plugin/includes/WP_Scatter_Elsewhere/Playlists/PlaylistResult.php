<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Playlists;

/**
 * The outcome of putting a video in playlists.
 */
final class PlaylistResult {

	/**
	 * @param string[]              $added   Playlist IDs the video was added to.
	 * @param string[]              $skipped Playlist IDs that already had the video.
	 * @param array<string, string> $errors  Playlist ID => message ("*" for an error that is not about one playlist).
	 */
	public function __construct(
		public readonly array $added,
		public readonly array $skipped,
		public readonly array $errors
	) {
	}

	public function hasErrors(): bool {
		return [] !== $this->errors;
	}

	public function errorSummary(): string {
		$parts = [];
		foreach ( $this->errors as $playlist => $message ) {
			$parts[] = '*' === $playlist ? $message : $playlist . ': ' . $message;
		}

		return implode( ' | ', $parts );
	}
}
