<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Subtitles;

/**
 * The outcome of the synchronisation of subtitle tracks, per language.
 */
final class SubtitleResult {

	public const INSERTED = 'inserted';
	public const REPLACED = 'replaced';

	/**
	 * @param array<string, string> $actions Language => INSERTED or REPLACED.
	 * @param array<string, string> $errors  Language => message ("*" for an error that is not about one language).
	 * @param array<string, int>    $removed Language => number of automatic caption tracks removed.
	 */
	public function __construct(
		public readonly array $actions,
		public readonly array $errors,
		public readonly array $removed = []
	) {
	}

	public function hasErrors(): bool {
		return [] !== $this->errors;
	}

	/**
	 * A one-line summary of the errors, empty when there are none.
	 */
	public function errorSummary(): string {
		$parts = [];
		foreach ( $this->errors as $language => $message ) {
			$parts[] = '*' === $language ? $message : $language . ': ' . $message;
		}

		return implode( ' | ', $parts );
	}
}
