<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;
use WP_Scatter_Elsewhere\YouTube\AccessTokenProvider;
use WP_Scatter_Elsewhere\YouTube\CaptionClient;
use WP_Scatter_Elsewhere\YouTube\CaptionException;
use WP_Scatter_Elsewhere\YouTube\OAuthClient;

class CaptionClientTest extends TestCase {

	private function client( array $response ): CaptionClient {
		$tokens = new AccessTokenProvider(
			new YouTubeSettings( static fn(): array => [ 'refresh_token' => 'r', 'status' => 'connected' ], static function ( array $v ): void {} ),
			new OAuthClient( 'id', 'secret', 'https://example.org/cb', static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => 'tok', 'expires_in' => 3600 ] ] ),
			static fn(): ?array => null,
			static function ( array $v, int $ttl ): void {},
			static function (): void {},
			static fn(): int => 0
		);

		return new CaptionClient( static fn(): array => $response, $tokens, static fn(): string => 'B' );
	}

	private static function listing( array $items ): array {
		return [ 'status' => 200, 'headers' => [], 'body' => (string) json_encode( [ 'items' => $items ] ) ];
	}

	public function test_lists_the_tracks_with_their_state(): void {
		$tracks = $this->client(
			self::listing(
				[
					[ 'id' => 'c1', 'snippet' => [ 'language' => 'fr', 'name' => 'FR', 'trackKind' => 'standard', 'status' => 'failed', 'failureReason' => 'unknownFormat', 'isDraft' => false ] ],
					[ 'id' => 'c2', 'snippet' => [ 'language' => 'fr', 'name' => '', 'trackKind' => 'asr', 'status' => 'serving' ] ],
					[ 'id' => 'c3', 'snippet' => [ 'language' => 'en', 'isDraft' => true ] ],
					[ 'snippet' => [ 'language' => 'de' ] ],
				]
			)
		)->tracks( 'vid' );

		$this->assertSame(
			[
				[ 'id' => 'c1', 'language' => 'fr', 'name' => 'FR', 'kind' => 'standard', 'status' => 'failed', 'failure' => 'unknownFormat', 'draft' => false ],
				[ 'id' => 'c2', 'language' => 'fr', 'name' => '', 'kind' => 'asr', 'status' => 'serving', 'failure' => '', 'draft' => false ],
				[ 'id' => 'c3', 'language' => 'en', 'name' => '', 'kind' => 'standard', 'status' => '', 'failure' => '', 'draft' => true ],
			],
			$tracks
		);
	}

	public function test_standard_tracks_ignore_automatic_ones(): void {
		$tracks = $this->client(
			self::listing(
				[
					[ 'id' => 'asr', 'snippet' => [ 'language' => 'fr', 'trackKind' => 'asr' ] ],
					[ 'id' => 'c1', 'snippet' => [ 'language' => 'fr', 'trackKind' => 'standard' ] ],
					[ 'id' => 'c2', 'snippet' => [ 'language' => 'fr', 'trackKind' => 'standard' ] ],
					[ 'id' => 'c3', 'snippet' => [ 'language' => 'en' ] ],
				]
			)
		)->standardTracks( 'vid' );

		$this->assertSame( [ 'fr' => 'c1', 'en' => 'c3' ], $tracks );
	}

	public function test_no_track_and_malformed_answers_give_an_empty_list(): void {
		$this->assertSame( [], $this->client( self::listing( [] ) )->tracks( 'vid' ) );
		$this->assertSame( [], $this->client( [ 'status' => 200, 'headers' => [], 'body' => 'garbage' ] )->tracks( 'vid' ) );
	}

	public function test_an_error_is_raised(): void {
		$this->expectException( CaptionException::class );

		$this->client( [ 'status' => 404, 'headers' => [], 'body' => '{}' ] )->tracks( 'vid' );
	}
}
