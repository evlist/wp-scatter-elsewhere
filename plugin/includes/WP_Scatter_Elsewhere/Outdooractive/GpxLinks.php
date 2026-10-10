<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Outdooractive;

use DOMDocument;
use DOMElement;

/**
 * Finds the links to GPX files in the HTML of a post.
 */
final class GpxLinks {

	/**
	 * @return string[] The addresses as written in the links (they may be relative), without duplicates, in order.
	 */
	public function find( string $html ): array {
		if ( '' === trim( $html ) ) {
			return [];
		}

		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$links = [];
		foreach ( $dom->getElementsByTagName( 'a' ) as $anchor ) {
			if ( ! $anchor instanceof DOMElement ) {
				continue;
			}

			$href = trim( $anchor->getAttribute( 'href' ) );
			$path = substr( $href, 0, strcspn( $href, '?#' ) );

			if ( '' !== $href && 'gpx' === strtolower( pathinfo( rawurldecode( $path ), PATHINFO_EXTENSION ) ) ) {
				$links[ $href ] = $href;
			}
		}

		return array_values( $links );
	}
}
