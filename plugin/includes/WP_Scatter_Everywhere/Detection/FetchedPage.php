<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\Detection;

final class FetchedPage {

	public function __construct(
		public readonly string $url,
		public readonly string $html
	) {
	}
}
