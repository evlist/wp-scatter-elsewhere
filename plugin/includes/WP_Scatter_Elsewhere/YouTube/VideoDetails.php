<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

/**
 * What YouTube says about a video.
 */
final class VideoDetails {

	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $privacy,
		public readonly string $publishedAt,
		public readonly string $channelId
	) {
	}
}
