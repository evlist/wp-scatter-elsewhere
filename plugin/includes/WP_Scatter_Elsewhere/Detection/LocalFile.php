<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Detection;

/**
 * A file of the uploads directory.
 */
final class LocalFile {

	public function __construct(
		public readonly string $path,
		public readonly string $url,
		public readonly string $mimeType,
		public readonly int $size,
		public readonly ?int $attachmentId
	) {
	}
}
