<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Matching;

/**
 * What is known of a post to find its video.
 */
final class PostFacts {

	/**
	 * @param int $timestamp Unix time of the date of the post (the event date for a back-dated post).
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $permalink,
		public readonly int $timestamp
	) {
	}
}
