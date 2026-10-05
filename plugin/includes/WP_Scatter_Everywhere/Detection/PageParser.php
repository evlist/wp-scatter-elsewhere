<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\Detection;

use DOMDocument;
use DOMElement;

/**
 * Extracts the `<video>` elements, their sources and subtitle tracks from an HTML page.
 */
final class PageParser {

	private UrlResolver $urls;

	public function __construct( UrlResolver $urls ) {
		$this->urls = $urls;
	}

	/**
	 * @return ParsedVideo[]
	 */
	public function parse( string $html, string $pageUrl ): array {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$base = $this->baseUrl( $dom, $pageUrl );

		$videos = [];
		foreach ( $dom->getElementsByTagName( 'video' ) as $element ) {
			$video = $this->parseVideo( $element, $base );
			if ( null !== $video ) {
				$videos[] = $video;
			}
		}

		return $videos;
	}

	private function baseUrl( DOMDocument $dom, string $pageUrl ): string {
		foreach ( $dom->getElementsByTagName( 'base' ) as $element ) {
			if ( '' !== trim( $element->getAttribute( 'href' ) ) ) {
				return $this->urls->resolve( $pageUrl, $element->getAttribute( 'href' ) );
			}
		}

		return $pageUrl;
	}

	private function parseVideo( DOMElement $element, string $base ): ?ParsedVideo {
		$sources = [];
		if ( '' !== trim( $element->getAttribute( 'src' ) ) ) {
			$sources[] = $this->urls->resolve( $base, $element->getAttribute( 'src' ) );
		}

		// The legacy HTML parser does not know void elements such as <source> and <track> and may
		// nest them, hence the search of all descendants rather than of direct children.
		foreach ( $element->getElementsByTagName( 'source' ) as $source ) {
			if ( '' !== trim( $source->getAttribute( 'src' ) ) ) {
				$sources[] = $this->urls->resolve( $base, $source->getAttribute( 'src' ) );
			}
		}

		if ( [] === $sources ) {
			return null;
		}

		$tracks = [];
		foreach ( $element->getElementsByTagName( 'track' ) as $track ) {
			$kind = strtolower( trim( $track->getAttribute( 'kind' ) ) );
			if ( '' !== $kind && 'subtitles' !== $kind && 'captions' !== $kind ) {
				continue;
			}
			if ( '' === trim( $track->getAttribute( 'src' ) ) ) {
				continue;
			}

			$language = strtolower( trim( $track->getAttribute( 'srclang' ) ) );
			$label    = trim( $track->getAttribute( 'label' ) );

			$tracks[] = [
				'url'      => $this->urls->resolve( $base, $track->getAttribute( 'src' ) ),
				'language' => '' === $language ? null : $language,
				'label'    => '' === $label ? null : $label,
			];
		}

		$poster = trim( $element->getAttribute( 'poster' ) );

		return new ParsedVideo( $sources, $tracks, '' === $poster ? null : $this->urls->resolve( $base, $poster ) );
	}
}
