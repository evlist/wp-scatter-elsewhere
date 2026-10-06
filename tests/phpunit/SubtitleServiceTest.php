<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Settings\UploadSettings;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;
use WP_Scatter_Elsewhere\Subtitles\SubtitleConverter;
use WP_Scatter_Elsewhere\Subtitles\SubtitleResult;
use WP_Scatter_Elsewhere\Subtitles\SubtitleService;
use WP_Scatter_Elsewhere\YouTube\AccessTokenProvider;
use WP_Scatter_Elsewhere\YouTube\CaptionClient;
use WP_Scatter_Elsewhere\YouTube\OAuthClient;

class SubtitleServiceTest extends TestCase {

	private const VTT = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nBonjour\n";
	private const SRT = "1\n00:00:01,000 --> 00:00:02,000\nBonjour\n";
	private const SBV = "0:00:01.000,0:00:02.000\nBonjour\n";

	/** @var array<int, array{method: string, url: string, headers: array<string, string>, body: string}> */
	private array $requests = [];

	/** @var array<int, array{status: int, headers: array<string, string>, body: string}> */
	private array $responses = [];

	/** @var array<string, string|false> */
	private array $files = [ '/u/a-fr.vtt' => self::VTT, '/u/a-en.vtt' => self::VTT, '/u/a-de.vtt' => self::VTT ];

	private function list( array $items ): array {
		return [ 'status' => 200, 'headers' => [], 'body' => (string) json_encode( [ 'items' => $items ] ) ];
	}

	private function ok(): array {
		return [ 'status' => 200, 'headers' => [], 'body' => '{}' ];
	}

	private function service( string $format = 'sbv' ): SubtitleService {
		$tokens = new AccessTokenProvider(
			new YouTubeSettings( static fn(): array => [ 'refresh_token' => 'r', 'status' => 'connected' ], static function ( array $v ): void {} ),
			new OAuthClient( 'id', 'secret', 'https://example.org/cb', static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => 'tok', 'expires_in' => 3600 ] ] ),
			static fn(): ?array => null,
			static function ( array $v, int $ttl ): void {},
			static function (): void {},
			static fn(): int => 0
		);
		$client = new CaptionClient(
			function ( string $method, string $url, array $headers, string $body ): array {
				$this->requests[] = compact( 'method', 'url', 'headers', 'body' );

				return array_shift( $this->responses ) ?? $this->ok();
			},
			$tokens,
			static fn(): string => 'BOUND'
		);

		return new SubtitleService(
			$client,
			new SubtitleConverter(),
			new UploadSettings( static fn(): array => [ 'subtitle_format' => $format ], static function ( array $v ): void {} ),
			fn( string $path ): string|false => $this->files[ $path ] ?? false
		);
	}

	private static function tracks( string ...$languages ): array {
		return array_map( static fn( string $language ): array => [ 'language' => $language, 'path' => '/u/a-' . $language . '.vtt' ], $languages );
	}

	public function test_adds_a_track_for_a_language_youtube_does_not_have(): void {
		$this->responses = [ $this->list( [] ) ];

		$result = $this->service()->sync( 'vid', self::tracks( 'fr' ) );

		$this->assertSame( [ 'fr' => SubtitleResult::INSERTED ], $result->actions );
		$this->assertFalse( $result->hasErrors() );
		$this->assertSame( 'GET', $this->requests[0]['method'] );
		$this->assertSame( 'https://www.googleapis.com/youtube/v3/captions?part=snippet&videoId=vid', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer tok', $this->requests[0]['headers']['Authorization'] );

		$insert = $this->requests[1];
		$this->assertSame( 'POST', $insert['method'] );
		$this->assertSame( 'https://www.googleapis.com/upload/youtube/v3/captions?uploadType=multipart&part=snippet', $insert['url'] );
		$this->assertSame( 'multipart/related; boundary=BOUND', $insert['headers']['Content-Type'] );
		$this->assertStringContainsString( '{"snippet":{"videoId":"vid","language":"fr","name":"fr","isDraft":false}}', $insert['body'] );
		$this->assertStringContainsString( self::SBV, $insert['body'] );
		$this->assertStringNotContainsString( 'WEBVTT', $insert['body'] );
		$this->assertStringNotContainsString( '-->', $insert['body'] );
	}

	public function test_the_track_gets_the_name_given_or_else_its_language_code(): void {
		$this->responses = [ $this->list( [] ) ];

		$this->service()->sync( 'vid', [ [ 'language' => 'fr', 'path' => '/u/a-fr.vtt', 'name' => 'Français' ], [ 'language' => 'en', 'path' => '/u/a-en.vtt', 'name' => '  ' ] ] );

		$this->assertStringContainsString( '"language":"fr","name":"Français"', $this->requests[1]['body'] );
		$this->assertStringContainsString( '"language":"en","name":"en"', $this->requests[2]['body'] );
	}

	public function test_replaces_the_track_of_a_language_youtube_already_has(): void {
		$this->responses = [ $this->list( [ [ 'id' => 'cap1', 'snippet' => [ 'language' => 'fr', 'trackKind' => 'standard' ] ] ] ) ];

		$result = $this->service()->sync( 'vid', self::tracks( 'fr' ) );

		$this->assertSame( [ 'fr' => SubtitleResult::REPLACED ], $result->actions );
		$this->assertSame( 'PUT', $this->requests[1]['method'] );
		$this->assertStringContainsString( '{"id":"cap1","snippet":{"isDraft":false}}', $this->requests[1]['body'] );
	}

	public function test_an_automatic_track_does_not_prevent_adding_a_standard_one(): void {
		$this->responses = [ $this->list( [ [ 'id' => 'asr1', 'snippet' => [ 'language' => 'fr', 'trackKind' => 'asr' ] ] ] ) ];

		$result = $this->service()->sync( 'vid', self::tracks( 'fr' ) );

		$this->assertSame( [ 'fr' => SubtitleResult::INSERTED ], $result->actions );
		$this->assertSame( 'POST', $this->requests[1]['method'] );
	}

	public function test_converts_to_srt_when_asked(): void {
		$this->responses = [ $this->list( [] ) ];

		$this->service( 'srt' )->sync( 'vid', self::tracks( 'fr' ) );

		$this->assertStringContainsString( self::SRT, $this->requests[1]['body'] );
	}

	public function test_sends_the_file_as_it_is_when_the_format_is_vtt(): void {
		$this->responses = [ $this->list( [] ) ];

		$this->service( 'vtt' )->sync( 'vid', self::tracks( 'fr' ) );

		$this->assertStringContainsString( self::VTT, $this->requests[1]['body'] );
	}

	public function test_only_the_first_track_of_a_language_is_sent_and_nothing_is_requested_without_tracks(): void {
		$this->responses = [ $this->list( [] ) ];
		$tracks           = array_merge( self::tracks( 'fr' ), [ [ 'language' => 'fr', 'path' => '/u/a-en.vtt' ] ], self::tracks( 'en' ) );

		$result = $this->service()->sync( 'vid', $tracks );

		$this->assertSame( [ 'fr', 'en' ], array_keys( $result->actions ) );
		$this->assertCount( 3, $this->requests );

		$this->requests = [];
		$this->service()->sync( 'vid', [] );
		$this->assertSame( [], $this->requests );
	}

	public function test_an_unreadable_or_empty_file_is_reported_and_the_others_are_still_sent(): void {
		$this->files['/u/a-en.vtt'] = false;
		$this->files['/u/a-de.vtt'] = "WEBVTT\n";
		$this->responses            = [ $this->list( [] ) ];

		$result = $this->service()->sync( 'vid', self::tracks( 'en', 'de', 'fr' ) );

		$this->assertSame( [ 'fr' => SubtitleResult::INSERTED ], $result->actions );
		$this->assertSame( [ 'en', 'de' ], array_keys( $result->errors ) );
		$this->assertStringContainsString( 'en:', $result->errorSummary() );
	}

	public function test_an_error_on_one_language_does_not_stop_the_others(): void {
		$this->responses = [ $this->list( [] ), [ 'status' => 400, 'headers' => [], 'body' => '{"error":{"message":"Bad file."}}' ] ];

		$result = $this->service()->sync( 'vid', self::tracks( 'fr', 'en' ) );

		$this->assertSame( [ 'en' => SubtitleResult::INSERTED ], $result->actions );
		$this->assertStringContainsString( 'Bad file.', $result->errors['fr'] );
	}

	public function test_a_quota_error_stops_everything(): void {
		$quota           = (string) json_encode( [ 'error' => [ 'message' => 'Quota.', 'errors' => [ [ 'reason' => 'quotaExceeded' ] ] ] ] );
		$this->responses = [ $this->list( [] ), [ 'status' => 403, 'headers' => [], 'body' => $quota ] ];

		$result = $this->service()->sync( 'vid', self::tracks( 'fr', 'en', 'de' ) );

		$this->assertSame( [], $result->actions );
		$this->assertSame( [ 'fr' ], array_keys( $result->errors ) );
		$this->assertCount( 2, $this->requests );
	}

	public function test_a_failure_to_list_the_tracks_is_reported_without_sending_anything(): void {
		$this->responses = [ [ 'status' => 404, 'headers' => [], 'body' => '{"error":{"message":"Video not found."}}' ] ];

		$result = $this->service()->sync( 'vid', self::tracks( 'fr' ) );

		$this->assertSame( [], $result->actions );
		$this->assertStringContainsString( 'Video not found.', $result->errors['*'] );
		$this->assertStringNotContainsString( '*:', $result->errorSummary() );
		$this->assertCount( 1, $this->requests );
	}
}
