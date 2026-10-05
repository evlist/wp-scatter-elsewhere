<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Detection;

/**
 * Finds the videos of a post, with their subtitle tracks.
 *
 * Implementations: RenderedPageDetector (loopback request of the public page).
 * Other strategies are possible, see docs/slices/003-video-and-subtitle-detection.md.
 */
interface VideoDetector {

	/**
	 * @return DetectedVideo[]
	 * @throws DetectionException When the post cannot be inspected.
	 */
	public function detect( int $postId ): array;
}
