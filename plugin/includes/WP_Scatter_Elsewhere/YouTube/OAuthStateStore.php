<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

use Closure;

/**
 * Single-use `state` values protecting the authorisation callback against forged requests.
 */
final class OAuthStateStore {

	public const LIFETIME_SECONDS = 600;

	/**
	 * @var Closure(int): ?string
	 */
	private Closure $get;

	/**
	 * @var Closure(int, string, int): void
	 */
	private Closure $set;

	/**
	 * @var Closure(int): void
	 */
	private Closure $delete;

	/**
	 * @var Closure(): string
	 */
	private Closure $random;

	/**
	 * @param Closure(int): ?string         $get    Returns the state stored for a user, or null (expired or none).
	 * @param Closure(int, string, int): void $set    Stores a state for a user with a lifetime in seconds.
	 * @param Closure(int): void            $delete
	 * @param Closure(): string             $random Returns an unpredictable string.
	 */
	public function __construct( Closure $get, Closure $set, Closure $delete, Closure $random ) {
		$this->get    = $get;
		$this->set    = $set;
		$this->delete = $delete;
		$this->random = $random;
	}

	public function issue( int $userId ): string {
		$state = ( $this->random )();
		( $this->set )( $userId, $state, self::LIFETIME_SECONDS );

		return $state;
	}

	/**
	 * Checks a state and invalidates it, whether it matched or not.
	 */
	public function consume( int $userId, string $state ): bool {
		$stored = ( $this->get )( $userId );
		( $this->delete )( $userId );

		return null !== $stored && '' !== $stored && '' !== $state && hash_equals( $stored, $state );
	}
}
