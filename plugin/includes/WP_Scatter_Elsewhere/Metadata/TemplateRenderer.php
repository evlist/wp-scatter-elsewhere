<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Metadata;

use Closure;
use DateTimeImmutable;

/**
 * Renders a template with the elements of a post.
 */
final class TemplateRenderer {

	private TemplateParser $parser;

	/**
	 * @var Closure(DateTimeImmutable, ?string): string
	 */
	private Closure $dateFormatter;

	/**
	 * @param Closure(DateTimeImmutable, ?string): string $dateFormatter Formats a date with a PHP date
	 *                                                                    format, or with the site date
	 *                                                                    format when given null.
	 */
	/**
	 * @var Closure(): string
	 */
	private Closure $locale;

	/**
	 * @param Closure(): string $locale Locale of the site, such as "fr_FR", for the ordinal day.
	 */
	public function __construct( TemplateParser $parser, Closure $dateFormatter, ?Closure $locale = null ) {
		$this->parser        = $parser;
		$this->dateFormatter = $dateFormatter;
		$this->locale        = $locale ?? static fn(): string => '';
	}

	/**
	 * @throws \InvalidArgumentException When the template syntax is invalid.
	 */
	public function render( string $template, PostData $post ): string {
		$output = '';

		foreach ( $this->parser->parse( $template ) as $node ) {
			$output .= $node instanceof Placeholder ? $this->resolve( $node, $post ) : $node;
		}

		return $output;
	}

	/**
	 * Unknown placeholders are left unchanged.
	 */
	private function resolve( Placeholder $placeholder, PostData $post ): string {
		switch ( $placeholder->name ) {
			case 'title':
				return $this->plainText( $post->title );
			case 'excerpt':
				return $this->plainText( $post->excerpt );
			case 'permalink':
				return $post->permalink;
			case 'author':
				return $this->plainText( $post->author );
			case 'date':
				return ( $this->dateFormatter )( $post->date, $placeholder->argument );
			case 'ordinal_day':
				return OrdinalDay::format( (int) $post->date->format( 'j' ), ( $this->locale )() );
			case 'section':
				return ( new SectionExtractor() )->section( $post->content(), (string) $placeholder->argument );
			case 'paragraphs':
				return ( new SectionExtractor() )->paragraphs( $post->content(), (int) $placeholder->argument );
			case 'categories':
				return $this->terms( $post, 'category' );
			case 'tags':
				return $this->terms( $post, 'post_tag' );
			case 'terms':
				return $this->terms( $post, (string) $placeholder->argument );
			default:
				return $placeholder->raw;
		}
	}

	private function terms( PostData $post, string $taxonomy ): string {
		$names = array_map( [ $this, 'plainText' ], $post->terms[ $taxonomy ] ?? [] );

		return implode( ', ', $names );
	}

	private function plainText( string $value ): string {
		$text = html_entity_decode( strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;

		return trim( $text );
	}
}
