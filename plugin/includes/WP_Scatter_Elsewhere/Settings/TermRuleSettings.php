<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Settings;

use Closure;
use InvalidArgumentException;
use WP_Scatter_Elsewhere\Rules\TermRule;
use WP_Scatter_Elsewhere\Rules\TermRuleValidator;

/**
 * The table of the rules that give playlists and keywords to the videos of the posts that have some terms.
 */
class TermRuleSettings {

	private const OPTION_KEY = 'wp_scatter_elsewhere_term_rules';

	/**
	 * @var Closure(): mixed
	 */
	private Closure $loader;

	/**
	 * @var Closure(array<int, array<string, mixed>>): void
	 */
	private Closure $saver;

	/**
	 * @param Closure(): mixed                                     $loader
	 * @param Closure(array<int, array<string, mixed>>): void $saver
	 */
	public function __construct( Closure $loader, Closure $saver ) {
		$this->loader = $loader;
		$this->saver  = $saver;
	}

	public static function optionKey(): string {
		return self::OPTION_KEY;
	}

	/**
	 * @return TermRule[]
	 */
	public function rules(): array {
		$stored = ( $this->loader )();
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$rules = [];
		foreach ( $stored as $data ) {
			$rule = is_array( $data ) ? TermRule::fromArray( $data ) : null;
			if ( null !== $rule ) {
				$rules[] = $rule;
			}
		}

		return $rules;
	}

	/**
	 * Validates the rows of the form and saves them. Nothing is saved when a row is invalid.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @throws InvalidArgumentException With the problems of all the rows.
	 */
	public function save( array $rows, TermRuleValidator $validator ): void {
		$result = $validator->validate( $rows );

		if ( [] !== $result['errors'] ) {
			throw new InvalidArgumentException( implode( ' ', $result['errors'] ) );
		}

		( $this->saver )( array_map( static fn( TermRule $rule ): array => $rule->toArray(), $result['rules'] ) );
	}
}
