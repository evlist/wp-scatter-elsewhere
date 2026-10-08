<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Metadata;

use InvalidArgumentException;

/**
 * Splits a template into literal text and placeholders, and validates it.
 *
 * Syntax: `{name}` or `{name:argument}`; `{{` and `}}` are literal braces.
 */
final class TemplateParser {

	/**
	 * Placeholder names and whether they take an argument:
	 * 'none' (forbidden), 'optional' or 'required'.
	 */
	private const PLACEHOLDERS = [
		'title'      => 'none',
		'excerpt'    => 'none',
		'permalink'  => 'none',
		'author'     => 'none',
		'categories' => 'none',
		'tags'       => 'none',
		'ordinal_day' => 'none',
		'date'       => 'optional',
		'terms'      => 'required',
	];

	/**
	 * Parses the syntax of a template without checking placeholder names.
	 *
	 * @return array<int, string|Placeholder>
	 * @throws InvalidArgumentException When braces are unbalanced or a placeholder is malformed.
	 */
	public function parse( string $template ): array {
		$nodes  = [];
		$text   = '';
		$length = strlen( $template );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $template[ $i ];
			$next = $template[ $i + 1 ] ?? '';

			if ( '{' === $char && '{' === $next ) {
				$text .= '{';
				++$i;
			} elseif ( '}' === $char && '}' === $next ) {
				$text .= '}';
				++$i;
			} elseif ( '}' === $char ) {
				throw new InvalidArgumentException( __( 'Unbalanced "}" in template. Use "}}" for a literal brace.', 'wp-scatter-elsewhere' ) );
			} elseif ( '{' === $char ) {
				$end = strpos( $template, '}', $i + 1 );
				if ( false === $end ) {
					throw new InvalidArgumentException( __( 'Unbalanced "{" in template. Use "{{" for a literal brace.', 'wp-scatter-elsewhere' ) );
				}

				$raw = substr( $template, $i, $end - $i + 1 );
				if ( false !== strpos( $raw, '{', 1 ) ) {
					throw new InvalidArgumentException( __( 'Unbalanced "{" in template. Use "{{" for a literal brace.', 'wp-scatter-elsewhere' ) );
				}

				if ( '' !== $text ) {
					$nodes[] = $text;
					$text    = '';
				}
				$nodes[] = $this->parsePlaceholder( $raw );
				$i       = $end;
			} else {
				$text .= $char;
			}
		}

		if ( '' !== $text ) {
			$nodes[] = $text;
		}

		return $nodes;
	}

	/**
	 * Returns an error message for an invalid template, or null when it is valid.
	 */
	public function validate( string $template ): ?string {
		try {
			$nodes = $this->parse( $template );
		} catch ( InvalidArgumentException $e ) {
			return $e->getMessage();
		}

		foreach ( $nodes as $node ) {
			if ( $node instanceof Placeholder ) {
				$error = $this->validatePlaceholder( $node );
				if ( null !== $error ) {
					return $error;
				}
			}
		}

		return null;
	}

	private function parsePlaceholder( string $raw ): Placeholder {
		$inner    = substr( $raw, 1, -1 );
		$colon    = strpos( $inner, ':' );
		$name     = false === $colon ? $inner : substr( $inner, 0, $colon );
		$argument = false === $colon ? null : substr( $inner, $colon + 1 );

		if ( 1 !== preg_match( '/^[a-z_]+$/', $name ) ) {
			throw new InvalidArgumentException(
				sprintf(
					/* translators: %s: the malformed placeholder, such as "{}". */
					__( 'Malformed placeholder %s.', 'wp-scatter-elsewhere' ),
					$raw
				)
			);
		}

		return new Placeholder( $name, $argument, $raw );
	}

	private function validatePlaceholder( Placeholder $placeholder ): ?string {
		$rule = self::PLACEHOLDERS[ $placeholder->name ] ?? null;

		if ( null === $rule ) {
			return sprintf(
				/* translators: %s: the unknown placeholder, such as "{foo}". */
				__( 'Unknown placeholder %s.', 'wp-scatter-elsewhere' ),
				$placeholder->raw
			);
		}

		$hasArgument = null !== $placeholder->argument;

		if ( 'none' === $rule && $hasArgument ) {
			return sprintf(
				/* translators: %s: a placeholder such as "{title:x}". */
				__( 'Placeholder %s does not take an argument.', 'wp-scatter-elsewhere' ),
				$placeholder->raw
			);
		}

		if ( ( 'required' === $rule || $hasArgument ) && '' === (string) $placeholder->argument ) {
			return sprintf(
				/* translators: %s: a placeholder such as "{date:}". */
				__( 'Placeholder %s needs a non-empty argument.', 'wp-scatter-elsewhere' ),
				$placeholder->raw
			);
		}

		return null;
	}
}
