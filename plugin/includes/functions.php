<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Template functions of the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_scatter_elsewhere_youtube_url' ) ) {
	/**
	 * Returns the YouTube address of a video of a post, or null.
	 *
	 * A private video is not returned unless $include_private is true.
	 *
	 * @param int|null    $post_id         Post ID; the current post by default.
	 * @param string|null $video_id        Detected video ID; the first video that can be shown by default.
	 * @param bool        $include_private Whether to return the address of a private video.
	 */
	function wp_scatter_elsewhere_youtube_url( ?int $post_id = null, ?string $video_id = null, bool $include_private = false ): ?string {
		return WP_Scatter_Elsewhere\Publication\WordPressFactory::youtubeUrl( $post_id, $video_id, $include_private );
	}
}
