<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Publication;

use WP_Scatter_Elsewhere\YouTube\VideoInspector;
use WP_Scatter_Elsewhere\YouTube\YouTubeConnectionException;

/**
 * Reads the real privacy of a YouTube video and records it.
 */
final class PublicationRefresher {

	private VideoInspector $inspector;

	private PublicationStore $store;

	public function __construct( VideoInspector $inspector, PublicationStore $store ) {
		$this->inspector = $inspector;
		$this->store     = $store;
	}

	/**
	 * @throws YouTubeConnectionException When YouTube cannot be read or no longer has the video.
	 */
	public function refresh( int $postId, Publication $publication, int $now ): Publication {
		$refreshed = new Publication(
			$publication->videoId,
			$publication->youtubeId,
			$this->inspector->privacy( $publication->youtubeId ),
			$publication->publishedAt,
			$publication->jobId,
			$now
		);

		$this->store->save( $postId, $refreshed );

		return $refreshed;
	}
}
