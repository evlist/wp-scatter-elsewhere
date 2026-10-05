<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Metadata;

/**
 * A `{name}` or `{name:argument}` element of a template.
 */
final class Placeholder {

	public function __construct(
		public readonly string $name,
		public readonly ?string $argument,
		public readonly string $raw
	) {
	}
}
