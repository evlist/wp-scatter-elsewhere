<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Update;

use WP_Scatter_Elsewhere\Rules\TermRule;

/**
 * What the plugin manages on the videos: the playlists and the keywords that at least one rule names. Only
 * these are ever removed, so what was added by hand on YouTube is left alone.
 */
final class ManagedSets {

	/**
	 * @param TermRule[] $rules
	 * @return string[]
	 */
	public static function playlists( array $rules ): array {
		$ids = [];
		foreach ( $rules as $rule ) {
			if ( '' !== $rule->playlistId ) {
				$ids[ $rule->playlistId ] = $rule->playlistId;
			}
		}

		return array_values( $ids );
	}

	/**
	 * @param TermRule[] $rules
	 * @return string[]
	 */
	public static function keywords( array $rules ): array {
		$keywords = [];
		foreach ( $rules as $rule ) {
			if ( '' !== $rule->keyword ) {
				$keywords[ mb_strtolower( $rule->keyword, 'UTF-8' ) ] ??= $rule->keyword;
			}
		}

		return array_values( $keywords );
	}
}
