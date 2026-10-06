<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Playlists;

use WP_Scatter_Elsewhere\YouTube\PlaylistClient;
use WP_Scatter_Elsewhere\YouTube\PlaylistException;

/**
 * Puts a video in playlists, skipping the ones that already have it.
 */
final class PlaylistService {

	private PlaylistClient $client;

	public function __construct( PlaylistClient $client ) {
		$this->client = $client;
	}

	/**
	 * A problem with one playlist does not stop the others; a quota error stops everything.
	 *
	 * @param string[] $playlistIds
	 */
	public function addTo( string $youtubeId, array $playlistIds ): PlaylistResult {
		$added   = [];
		$skipped = [];
		$errors  = [];

		foreach ( array_values( array_unique( $playlistIds ) ) as $playlistId ) {
			try {
				if ( $this->client->contains( $playlistId, $youtubeId ) ) {
					$skipped[] = $playlistId;
					continue;
				}

				$this->client->add( $playlistId, $youtubeId );
				$added[] = $playlistId;
			} catch ( PlaylistException $e ) {
				$errors[ $playlistId ] = $e->getMessage();

				if ( $e->isQuota() ) {
					break;
				}
			}
		}

		return new PlaylistResult( $added, $skipped, $errors );
	}
}
