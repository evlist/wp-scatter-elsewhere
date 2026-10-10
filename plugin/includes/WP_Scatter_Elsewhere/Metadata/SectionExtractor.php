<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Metadata;

use DOMDocument;
use DOMElement;
use DOMXPath;
use WP_Scatter_Elsewhere\Text\Folding;

/**
 * Takes plain text out of the HTML of a post: the paragraphs under a heading, or the first paragraphs.
 */
final class SectionExtractor {

	private const TEXT_ELEMENTS = [ 'p', 'li', 'blockquote' ];

	/**
	 * The text under the first heading whose text is the given one (case, accents and punctuation ignored, so an emoji
	 * in the heading does not matter), up to the next heading of the same or a higher level. Paragraphs are separated by
	 * a blank line; empty when there is no such heading.
	 */
	public function section( string $html, string $heading ): string {
		$wanted = Folding::fold( $heading );
		if ( '' === $wanted ) {
			return '';
		}

		$level = null;
		$parts = [];

		foreach ( $this->elements( $html ) as $element ) {
			$name = strtolower( $element->nodeName );

			if ( 1 === preg_match( '/^h([1-6])$/', $name, $matches ) ) {
				if ( null !== $level ) {
					if ( (int) $matches[1] <= $level ) {
						break;
					}
					continue;
				}

				if ( Folding::fold( $element->textContent ) === $wanted ) {
					$level = (int) $matches[1];
				}
				continue;
			}

			if ( null !== $level ) {
				$text = $this->text( $element );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * The first paragraphs of the post (not the items of lists or the quotes), without the empty ones.
	 */
	public function paragraphs( string $html, int $count ): string {
		$parts = [];

		foreach ( $this->elements( $html ) as $element ) {
			if ( count( $parts ) >= $count ) {
				break;
			}

			if ( 'p' === strtolower( $element->nodeName ) ) {
				$text = $this->text( $element );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * The headings and the text elements, in document order, the text elements nested in another one left out.
	 *
	 * @return DOMElement[]
	 */
	private function elements( string $html ): array {
		if ( '' === trim( $html ) ) {
			return [];
		}

		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$names = array_merge( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], self::TEXT_ELEMENTS );
		$query = '//*[' . implode( ' or ', array_map( static fn( string $name ): string => 'self::' . $name, $names ) ) . ']';

		$elements = [];
		foreach ( ( new DOMXPath( $dom ) )->query( $query ) ?: [] as $element ) {
			if ( $element instanceof DOMElement && ! $this->insideTextElement( $element ) ) {
				$elements[] = $element;
			}
		}

		return $elements;
	}

	private function insideTextElement( DOMElement $element ): bool {
		for ( $parent = $element->parentNode; null !== $parent; $parent = $parent->parentNode ) {
			if ( $parent instanceof DOMElement && in_array( strtolower( $parent->nodeName ), self::TEXT_ELEMENTS, true ) ) {
				return true;
			}
		}

		return false;
	}

	private function text( DOMElement $element ): string {
		// A line break separates words.
		foreach ( iterator_to_array( $element->getElementsByTagName( 'br' ) ) as $break ) {
			$break->parentNode?->replaceChild( $element->ownerDocument->createTextNode( ' ' ), $break );
		}

		$text = html_entity_decode( $element->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
