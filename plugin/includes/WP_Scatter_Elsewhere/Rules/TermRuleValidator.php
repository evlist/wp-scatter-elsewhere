<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Rules;

use Closure;

/**
 * Turns the rows of the settings form into rules, and reports what is wrong with them.
 */
final class TermRuleValidator {

	/**
	 * @var Closure(string, string): bool
	 */
	private Closure $termExists;

	/**
	 * @param Closure(string, string): bool $termExists Whether a term (taxonomy, slug) exists.
	 */
	public function __construct( Closure $termExists ) {
		$this->termExists = $termExists;
	}

	/**
	 * Empty rows are ignored; rows marked "remove" are dropped.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array{rules: TermRule[], errors: string[]} Errors are readable messages that name the row.
	 */
	public function validate( array $rows ): array {
		$rules  = [];
		$errors = [];
		$seen   = [];
		$number = 0;

		foreach ( $rows as $row ) {
			++$number;

			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}

			$rule     = TermRule::fromArray( $row );
			$playlist = trim( (string) ( $row['playlist_id'] ?? '' ) );
			$keyword  = trim( (string) ( $row['keyword'] ?? '' ) );
			$category = trim( (string) ( $row['category_id'] ?? '' ) );

			if ( null === $rule ) {
				if ( '' !== $playlist || '' !== $keyword || '' !== $category ) {
					$errors[] = $this->message( $number, __( 'choose a term.', 'wp-scatter-elsewhere' ) );
				}
				continue;
			}

			$problem = $this->problem( $rule, $seen );
			if ( null !== $problem ) {
				$errors[] = $this->message( $number, $problem );
				continue;
			}

			$seen[ $rule->taxonomy . ':' . $rule->term ] = true;
			$rules[]                                    = new TermRule( $rule->taxonomy, $rule->term, $rule->playlistId, $this->cleanKeyword( $rule->keyword ), $rule->includeChildren, $rule->categoryId );
		}

		return [ 'rules' => $rules, 'errors' => $errors ];
	}

	/**
	 * @param array<string, true> $seen
	 */
	private function problem( TermRule $rule, array $seen ): ?string {
		if ( ! ( $this->termExists )( $rule->taxonomy, $rule->term ) ) {
			return __( 'this term does not exist.', 'wp-scatter-elsewhere' );
		}

		if ( isset( $seen[ $rule->taxonomy . ':' . $rule->term ] ) ) {
			return __( 'this term is already in the table.', 'wp-scatter-elsewhere' );
		}

		if ( '' === $rule->playlistId && '' === $this->cleanKeyword( $rule->keyword ) && '' === $rule->categoryId ) {
			return __( 'give a playlist, a keyword, a category, or several of them.', 'wp-scatter-elsewhere' );
		}

		if ( '' !== $rule->categoryId && 1 !== preg_match( '/^[0-9]{1,3}$/', $rule->categoryId ) ) {
			return __( 'the category must be the number of a YouTube category, such as 22.', 'wp-scatter-elsewhere' );
		}

		if ( '' !== $rule->playlistId && 1 !== preg_match( '/^[A-Za-z0-9_-]{10,64}$/', $rule->playlistId ) ) {
			return __( 'the playlist ID is not valid.', 'wp-scatter-elsewhere' );
		}

		return null;
	}

	private function cleanKeyword( string $keyword ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', str_replace( [ '<', '>' ], '', $keyword ) ) );
	}

	private function message( int $row, string $problem ): string {
		return sprintf(
			/* translators: 1: row number, 2: what is wrong. */
			__( 'Row %1$d: %2$s', 'wp-scatter-elsewhere' ),
			$row,
			$problem
		);
	}
}
