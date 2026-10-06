<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Rules;

use WP_Scatter_Elsewhere\Settings\TermRuleSettings;
use WP_Scatter_Elsewhere\YouTube\WordPressFactory as YouTubeFactory;
use WP_Scatter_Elsewhere\YouTube\YouTubeConnectionException;

/**
 * Wires the term rules to WordPress. Contains no logic worth testing without WordPress.
 */
final class WordPressFactory {

	private const PLAYLISTS_TRANSIENT = 'wp_scatter_elsewhere_playlists';

	public static function settings(): TermRuleSettings {
		return new TermRuleSettings(
			static fn(): mixed => get_option( TermRuleSettings::optionKey(), false ),
			static function ( array $value ): void {
				update_option( TermRuleSettings::optionKey(), $value, false );
			}
		);
	}

	public static function validator(): TermRuleValidator {
		return new TermRuleValidator(
			static fn( string $taxonomy, string $slug ): bool => taxonomy_exists( $taxonomy ) && false !== get_term_by( 'slug', $slug, $taxonomy )
		);
	}

	public static function forgetPlaylistChoices(): void {
		delete_transient( self::PLAYLISTS_TRANSIENT );
	}

	/**
	 * The playlists of the channel as id => title, kept for an hour. Empty when YouTube cannot be reached.
	 *
	 * @return array<string, string>
	 */
	public static function playlistChoices( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_transient( self::PLAYLISTS_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		try {
			$playlists = YouTubeFactory::playlistClient()->playlists();
		} catch ( YouTubeConnectionException $e ) {
			return [];
		}

		set_transient( self::PLAYLISTS_TRANSIENT, $playlists, HOUR_IN_SECONDS );

		return $playlists;
	}
}
