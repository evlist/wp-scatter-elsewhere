<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Update;

use WP_Scatter_Elsewhere\YouTube\WordPressFactory as YouTubeFactory;

/**
 * Wires the batch update to the YouTube clients. Contains no logic worth testing without WordPress.
 */
final class WordPressFactory {

	public static function batchUpdater(): BatchUpdater {
		$updater   = YouTubeFactory::videoUpdater();
		$playlists = YouTubeFactory::playlistClient();

		return new BatchUpdater(
			static fn( string $youtubeId ): array => $updater->snapshot( $youtubeId ),
			static fn( string $youtubeId, array $changes ): array => $updater->update( $youtubeId, $changes ),
			static fn( string $playlistId, string $youtubeId ): bool => $playlists->contains( $playlistId, $youtubeId ),
			static function ( string $playlistId, string $youtubeId ) use ( $playlists ): void {
				$playlists->add( $playlistId, $youtubeId );
			},
			static fn( string $playlistId, string $youtubeId ): array => $playlists->itemIds( $playlistId, $youtubeId ),
			static function ( string $itemId ) use ( $playlists ): void {
				$playlists->remove( $itemId );
			},
			new VideoDiff()
		);
	}
}
