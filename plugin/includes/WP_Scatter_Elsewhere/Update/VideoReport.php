<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Update;

/**
 * What the update of one video did, or would do.
 */
final class VideoReport {

	public const COST_READ          = 1;
	public const COST_UPDATE        = 51; // The update (50) and the read it makes first (1).
	public const COST_PLAYLIST_ITEM = 50;
	public const COST_THUMBNAIL     = 50;
	public const COST_SUBTITLES     = 400;

	/**
	 * @param array<int, array{field: string, current: string, new: string, action: string}> $rows
	 * @param int                                                                            $cost    Quota units spent, or that applying would spend.
	 * @param bool                                                                           $stopped Whether YouTube refused for lack of quota: the run must stop.
	 */
	public function __construct(
		public readonly array $rows,
		public readonly int $cost,
		public readonly int $errors,
		public readonly bool $stopped
	) {
	}

	public function changed(): bool {
		return [] !== $this->rows;
	}
}
