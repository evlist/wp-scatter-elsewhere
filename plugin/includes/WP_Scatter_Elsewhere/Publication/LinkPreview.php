<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Publication;

/**
 * What the author is shown before confirming a link.
 */
final class LinkPreview {

	/**
	 * @param bool                                       $channelChecked Whether the video was checked to belong to the connected channel.
	 * @param array{post_id: int, video_id: string}|null $linkedTo       Where the YouTube video is already linked, if anywhere else.
	 */
	public function __construct(
		public readonly string $youtubeId,
		public readonly string $title,
		public readonly string $privacy,
		public readonly string $publishedAt,
		public readonly bool $channelChecked,
		public readonly ?array $linkedTo
	) {
	}
}
