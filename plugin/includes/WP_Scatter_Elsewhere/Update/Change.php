<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Update;

/**
 * A difference between what a video should be and what it is on YouTube, for one field.
 */
final class Change {

	/**
	 * @param array<string, string|bool|string[]> $payload What the video updater needs to apply it.
	 */
	public function __construct(
		public readonly string $field,
		public readonly string $current,
		public readonly string $new,
		public readonly array $payload
	) {
	}
}
