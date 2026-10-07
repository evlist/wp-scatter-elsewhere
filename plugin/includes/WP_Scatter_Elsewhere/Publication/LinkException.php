<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Publication;

use RuntimeException;

/**
 * A YouTube video cannot be linked to a video of the blog. $errorCode tells why: "invalid_address",
 * "unreadable", "other_channel" or "already_linked" (then $linkedTo says where it is linked).
 */
final class LinkException extends RuntimeException {

	private string $errorCode;

	/**
	 * @var array{post_id: int, video_id: string}|null
	 */
	private ?array $linkedTo;

	/**
	 * @param array{post_id: int, video_id: string}|null $linkedTo
	 */
	public function __construct( string $errorCode, string $message, ?array $linkedTo = null ) {
		parent::__construct( $message );
		$this->errorCode = $errorCode;
		$this->linkedTo  = $linkedTo;
	}

	public function errorCode(): string {
		return $this->errorCode;
	}

	/**
	 * @return array{post_id: int, video_id: string}|null
	 */
	public function linkedTo(): ?array {
		return $this->linkedTo;
	}
}
