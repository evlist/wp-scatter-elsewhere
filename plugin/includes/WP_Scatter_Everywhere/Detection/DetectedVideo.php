<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\Detection;

/**
 * A video found in a post. $file is null when it cannot be uploaded; $reason then says why.
 */
final class DetectedVideo {

	/**
	 * @param string[]        $sources   Absolute URLs of all the sources, for display.
	 * @param SubtitleTrack[] $subtitles
	 */
	public function __construct(
		public readonly string $id,
		public readonly ?LocalFile $file,
		public readonly ?string $reason,
		public readonly array $sources,
		public readonly array $subtitles,
		public readonly ?string $poster
	) {
	}

	public function isUploadable(): bool {
		return null !== $this->file;
	}
}
