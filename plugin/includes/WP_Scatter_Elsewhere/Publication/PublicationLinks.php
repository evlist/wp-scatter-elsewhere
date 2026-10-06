<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Publication;

/**
 * Chooses the publication to show a link to. A private video is never advertised.
 */
final class PublicationLinks {

	/**
	 * @param array<string, Publication> $publications Publications of a post, in recording order.
	 * @param ?string                    $videoId      Detected video id, or null for the first one that can be shown.
	 */
	public static function select( array $publications, ?string $videoId, bool $includePrivate ): ?Publication {
		foreach ( $publications as $publication ) {
			if ( null !== $videoId && $publication->videoId !== $videoId ) {
				continue;
			}

			if ( $publication->isPrivate() && ! $includePrivate ) {
				continue;
			}

			return $publication;
		}

		return null;
	}
}
