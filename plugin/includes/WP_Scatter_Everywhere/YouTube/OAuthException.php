<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\YouTube;

/**
 * An OAuth request failed. $errorCode is the OAuth error (such as "invalid_grant") or a code of
 * this plugin ("transport", "no_refresh_token", "invalid_state", ...).
 */
final class OAuthException extends YouTubeConnectionException {

	private string $errorCode;

	public function __construct( string $errorCode, string $message ) {
		parent::__construct( $message );
		$this->errorCode = $errorCode;
	}

	public function errorCode(): string {
		return $this->errorCode;
	}

	public function isInvalidGrant(): bool {
		return 'invalid_grant' === $this->errorCode;
	}
}
