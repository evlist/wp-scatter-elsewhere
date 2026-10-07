<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Matching;

use WP_Scatter_Elsewhere\Publication\ChannelCatalog;

/**
 * Proposes videos of the channel, not yet linked, for a post.
 */
final class VideoSuggester {

	private ChannelCatalog $catalog;

	private VideoMatcher $matcher;

	public function __construct( ChannelCatalog $catalog, VideoMatcher $matcher ) {
		$this->catalog = $catalog;
		$this->matcher = $matcher;
	}

	/**
	 * @return Suggestion[]
	 * @throws \WP_Scatter_Elsewhere\YouTube\ChannelCatalogException When the channel cannot be read.
	 */
	public function suggest( PostFacts $post, bool $refresh = false ): array {
		$result = $this->catalog->list( $refresh, '', true );

		return $this->matcher->match( $post, array_map( static fn( array $row ) => $row['video'], $result['videos'] ) );
	}
}
