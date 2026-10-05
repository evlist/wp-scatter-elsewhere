<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\Detection;

/**
 * A subtitle track of a video. It is usable only when it has a local file and a language.
 */
final class SubtitleTrack {

	public function __construct(
		public readonly string $url,
		public readonly ?string $language,
		public readonly ?string $label,
		public readonly ?LocalFile $file,
		public readonly ?string $reason
	) {
	}

	public function isUsable(): bool {
		return null !== $this->file && null !== $this->language;
	}
}
