<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

/**
 * The properties of a video sent to YouTube.
 */
final class VideoMetadata {

	public const DEFAULT_CATEGORY_ID = '22';

	/**
	 * @param ?string $language      YouTube language code, or null to send none.
	 * @param string  $license       "youtube" or "creativeCommon".
	 * @param ?string $recordingDate ISO 8601 UTC date-time, or null to send none.
	 * @param ?string $thumbnailSource Path of the image to send as thumbnail, or null to send none.
	 * @param string[] $keywords        Keywords sent with the video.
	 * @param string[] $playlists       IDs of the playlists the video is put in once uploaded.
	 * @param string[] $droppedKeywords Keywords left out because they do not fit the limit of YouTube.
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $description,
		public readonly ?string $language,
		public readonly string $license,
		public readonly ?string $recordingDate,
		public readonly string $categoryId = self::DEFAULT_CATEGORY_ID,
		public readonly ?string $thumbnailSource = null,
		public readonly array $keywords = [],
		public readonly array $playlists = [],
		public readonly array $droppedKeywords = [],
		public readonly bool $embeddable = true,
		public readonly bool $publicStatsViewable = true,
		public readonly bool $madeForKids = false,
		public readonly bool $notifySubscribers = true
	) {
	}
}
