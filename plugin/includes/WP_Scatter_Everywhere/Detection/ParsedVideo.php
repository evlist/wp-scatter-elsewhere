<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\Detection;

/**
 * A `<video>` element as found in a page, with absolute URLs.
 */
final class ParsedVideo {

	/**
	 * @param string[]                                                     $sources
	 * @param array<int, array{url: string, language: ?string, label: ?string}> $tracks
	 */
	public function __construct(
		public readonly array $sources,
		public readonly array $tracks,
		public readonly ?string $poster
	) {
	}
}
