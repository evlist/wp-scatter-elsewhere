<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Matching;

/**
 * What the bulk linking decides for a video of a post.
 */
final class BulkDecision {

	public const ALREADY_LINKED = 'already linked';
	public const NO_CANDIDATE   = 'no candidate';
	public const WOULD_LINK     = 'would link';
	public const NEEDS_DECISION = 'needs a decision';

	public function __construct(
		public readonly BulkEntry $entry,
		public readonly string $decision,
		public readonly ?Suggestion $suggestion,
		public readonly string $note = ''
	) {
	}
}
