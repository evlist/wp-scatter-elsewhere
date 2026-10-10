<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Outdooractive;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Gives a GPX file a name and a description, which Outdooractive reads as the title and the description of the
 * trace it creates. Both the metadata and the first track are set, since the file may carry the values of the
 * application that recorded it. Everything else in the file is kept.
 */
final class GpxEnricher {

	private const GPX_NAMESPACE = 'http://www.topografix.com/GPX/1/1';

	/** Elements of a track that come before the description, in the order of the GPX schema. */
	private const BEFORE_TRACK_DESCRIPTION = [ 'name', 'cmt' ];

	/** Elements of the metadata that come before the description. */
	private const BEFORE_METADATA_DESCRIPTION = [ 'name' ];

	/**
	 * @throws InvalidArgumentException When the content is not a GPX file.
	 */
	public function enrich( string $gpx, string $name, string $description ): string {
		// A GPX file has no DTD: refusing one rules out entity tricks.
		if ( '' === trim( $gpx ) || false !== stripos( $gpx, '<!DOCTYPE' ) ) {
			throw new InvalidArgumentException( __( 'This file is not a GPX file.', 'wp-scatter-elsewhere' ) );
		}

		$dom                     = new DOMDocument( '1.0', 'UTF-8' );
		$dom->preserveWhiteSpace = true;
		$dom->formatOutput       = false;

		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadXML( $gpx, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $loaded ? $dom->documentElement : null;
		if ( ! $root instanceof DOMElement || 'gpx' !== $root->localName ) {
			throw new InvalidArgumentException( __( 'This file is not a GPX file.', 'wp-scatter-elsewhere' ) );
		}

		$namespace = $root->namespaceURI ?? self::GPX_NAMESPACE;

		$metadata = $this->child( $root, 'metadata' );
		if ( null === $metadata ) {
			$metadata = $dom->createElementNS( $namespace, 'metadata' );
			$root->insertBefore( $metadata, $root->firstChild );
		}
		$this->set( $dom, $namespace, $metadata, 'name', $name, [] );
		$this->set( $dom, $namespace, $metadata, 'desc', $description, self::BEFORE_METADATA_DESCRIPTION );

		$track = $this->child( $root, 'trk' );
		if ( null !== $track ) {
			$this->set( $dom, $namespace, $track, 'name', $name, [] );
			$this->set( $dom, $namespace, $track, 'desc', $description, self::BEFORE_TRACK_DESCRIPTION );
		}

		return (string) $dom->saveXML();
	}

	private function child( DOMElement $parent, string $name ): ?DOMElement {
		foreach ( $parent->childNodes as $node ) {
			if ( $node instanceof DOMElement && $name === $node->localName ) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Sets the text of a child element, creating it at its place in the schema order when it is missing.
	 *
	 * @param string[] $before Names of the children that must stay before it.
	 */
	private function set( DOMDocument $dom, string $namespace, DOMElement $parent, string $name, string $text, array $before ): void {
		$element = $this->child( $parent, $name );

		if ( null === $element ) {
			$element = $dom->createElementNS( $namespace, $name );

			// After the last of the elements that must come before, else first.
			$anchor = null;
			foreach ( $before as $earlier ) {
				$found = $this->child( $parent, $earlier );
				if ( null !== $found ) {
					$anchor = $found;
				}
			}

			if ( null === $anchor ) {
				$parent->insertBefore( $element, $parent->firstChild );
			} else {
				$parent->insertBefore( $element, $anchor->nextSibling );
			}
		}

		$element->textContent = $text;
	}
}
