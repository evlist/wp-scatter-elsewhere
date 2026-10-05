<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\YouTube\ChannelClient;

class ChannelClientTest extends TestCase {

	public function test_reads_the_authorised_channel(): void {
		$seen   = null;
		$client = new ChannelClient(
			static function ( string $method, string $url, array $headers, ?array $form ) use ( &$seen ): array {
				$seen = [ $method, $url, $headers, $form ];

				return [ 'status' => 200, 'body' => [ 'items' => [ [ 'id' => 'UC1', 'snippet' => [ 'title' => 'My channel' ] ] ] ] ];
			}
		);

		$this->assertSame( [ 'id' => 'UC1', 'title' => 'My channel' ], $client->fetch( 'token' ) );
		$this->assertSame( 'GET', $seen[0] );
		$this->assertStringContainsString( 'mine=true', $seen[1] );
		$this->assertSame( [ 'Authorization' => 'Bearer token' ], $seen[2] );
		$this->assertNull( $seen[3] );
	}

	public function test_returns_null_when_the_channel_cannot_be_read(): void {
		$bodies = [
			[ 'status' => 403, 'body' => [] ],
			[ 'status' => 200, 'body' => [ 'items' => [] ] ],
		];

		foreach ( $bodies as $response ) {
			$this->assertNull( ( new ChannelClient( static fn(): array => $response ) )->fetch( 't' ) );
		}

		$this->assertNull(
			( new ChannelClient( static function (): array {
				throw new RuntimeException( 'down' );
			} ) )->fetch( 't' )
		);
	}
}
