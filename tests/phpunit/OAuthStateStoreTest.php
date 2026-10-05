<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Everywhere\YouTube\OAuthStateStore;

class OAuthStateStoreTest extends TestCase {

	/**
	 * @var array<int, string>
	 */
	private array $states = [];

	private int $counter = 0;

	private function store(): OAuthStateStore {
		return new OAuthStateStore(
			fn( int $user ): ?string => $this->states[ $user ] ?? null,
			function ( int $user, string $state, int $ttl ): void {
				$this->states[ $user ] = $state;
			},
			function ( int $user ): void {
				unset( $this->states[ $user ] );
			},
			fn(): string => 'state-' . ++$this->counter
		);
	}

	public function test_a_state_is_valid_once(): void {
		$store = $this->store();
		$state = $store->issue( 5 );

		$this->assertTrue( $store->consume( 5, $state ) );
		$this->assertFalse( $store->consume( 5, $state ) );
	}

	public function test_a_wrong_state_is_refused_and_invalidates_the_issued_one(): void {
		$store = $this->store();
		$state = $store->issue( 5 );

		$this->assertFalse( $store->consume( 5, 'forged' ) );
		$this->assertFalse( $store->consume( 5, $state ) );
	}

	public function test_missing_empty_or_other_users_states_are_refused(): void {
		$store = $this->store();
		$state = $store->issue( 5 );

		$this->assertFalse( $store->consume( 6, $state ) );
		$this->assertFalse( $store->consume( 5, '' ) );
		$this->assertFalse( $this->store()->consume( 9, 'anything' ) );
	}
}
