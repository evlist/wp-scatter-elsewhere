<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

/**
 * A playlist could not be listed, read or changed. $isQuota tells that YouTube refused for lack of quota.
 */
final class PlaylistException extends YouTubeConnectionException {

	private bool $quota;

	public function __construct( string $message, bool $quota = false ) {
		parent::__construct( $message );
		$this->quota = $quota;
	}

	public function isQuota(): bool {
		return $this->quota;
	}
}
