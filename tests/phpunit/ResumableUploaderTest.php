<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;
use WP_Scatter_Elsewhere\YouTube\AccessTokenProvider;
use WP_Scatter_Elsewhere\YouTube\OAuthClient;
use WP_Scatter_Elsewhere\YouTube\Upload\ResumableUploader;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadJob;

/**
 * A minimal in-memory YouTube resumable upload endpoint, with scriptable failures.
 */
class FakeUploadServer {

	/** @var array<int, array{method: string, url: string, headers: array<string, string>, body: string}> */
	public array $requests = [];

	public string $received = '';

	public int $sessions = 0;

	public int $putDelay = 0;

	/** @var array<int, array|Closure> One entry consumed per request; empty means default behaviour. */
	public array $script = [];

	public int $now = 1000;

	public function __construct( public int $total ) {
	}

	/**
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function handle( string $method, string $url, array $headers, string $body ): array {
		$this->requests[] = compact( 'method', 'url', 'headers', 'body' );

		if ( 'PUT' === $method ) {
			$this->now += $this->putDelay;
		}

		$override = array_shift( $this->script );
		if ( $override instanceof Closure ) {
			return $override();
		}
		if ( is_array( $override ) ) {
			return $override;
		}

		if ( 'POST' === $method ) {
			++$this->sessions;
			$this->received = '';

			return [ 'status' => 200, 'headers' => [ 'location' => 'https://upload.example/session' . $this->sessions ], 'body' => '' ];
		}

		if ( str_starts_with( $headers['Content-Range'], 'bytes */' ) ) {
			return $this->progress();
		}

		preg_match( '#^bytes (\d+)-(\d+)/(\d+)$#', $headers['Content-Range'], $m );
		if ( (int) $m[1] === strlen( $this->received ) ) {
			$this->received .= $body;
		}

		return $this->progress();
	}

	/**
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private function progress(): array {
		if ( strlen( $this->received ) >= $this->total ) {
			return [ 'status' => 200, 'headers' => [], 'body' => '{"id":"vid123"}' ];
		}

		$headers = strlen( $this->received ) > 0 ? [ 'range' => 'bytes=0-' . ( strlen( $this->received ) - 1 ) ] : [];

		return [ 'status' => 308, 'headers' => $headers, 'body' => '' ];
	}
}

class ResumableUploaderTest extends TestCase {

	private const CONTENT = 'abcdefghijklmnopqrstuvwxy'; // 25 bytes.

	private FakeUploadServer $server;

	private function job( array $changes = [] ): UploadJob {
		return UploadJob::fromArray(
			array_merge(
				[
					'id'          => 'j1',
					'post_id'     => 7,
					'video_id'    => 'v1',
					'file_path'   => '/uploads/a.mp4',
					'mime_type'   => 'video/mp4',
					'size'        => strlen( self::CONTENT ),
					'title'       => 'Tour du Mont Blanc',
					'description' => 'Une belle randonnée.',
					'privacy'     => 'private',
					'language'    => 'fr',
					'keywords'    => [ 'vanlife', 'Salers' ],
					'license'     => 'creativeCommon',
					'recording_date' => '2026-10-05T12:00:00Z',
					'status'      => 'queued',
				],
				$changes
			)
		);
	}

	private function uploader( array $settings = [ 'refresh_token' => 'r', 'status' => 'connected' ], ?Closure $reader = null ): ResumableUploader {
		$this->server ??= new FakeUploadServer( strlen( self::CONTENT ) );
		$server        = $this->server;
		$tokenOauth    = new OAuthClient(
			'id',
			'secret',
			'https://example.org/cb',
			static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => 'tok', 'expires_in' => 3600 ] ]
		);
		$tokens        = new AccessTokenProvider(
			new YouTubeSettings( static fn(): array => $settings, static function ( array $v ): void {} ),
			$tokenOauth,
			static fn(): ?array => null,
			static function ( array $v, int $ttl ): void {},
			static function (): void {},
			static fn(): int => 0
		);

		return new ResumableUploader(
			fn( string $m, string $u, array $h, string $b ): array => $server->handle( $m, $u, $h, $b ),
			$tokens,
			$reader ?? static fn( string $path, int $offset, int $length ): string => substr( self::CONTENT, $offset, $length ),
			fn(): int => $server->now,
			10
		);
	}

	protected function setUp(): void {
		$this->server = new FakeUploadServer( strlen( self::CONTENT ) );
	}

	public function test_uploads_in_chunks_and_completes(): void {
		$job = $this->uploader()->run( $this->job(), 600 );

		$this->assertSame( UploadJob::STATUS_DONE, $job->status() );
		$this->assertSame( 'vid123', $job->youtubeId() );
		$this->assertSame( self::CONTENT, $this->server->received );
		$this->assertNull( $job->sessionUri() );
		$this->assertCount( 4, $this->server->requests );
	}

	public function test_opens_the_session_with_the_metadata_and_the_file_description(): void {
		$this->uploader()->run( $this->job(), 600 );

		$init = $this->server->requests[0];
		$this->assertSame( 'POST', $init['method'] );
		$this->assertSame( ResumableUploader::INIT_ENDPOINT . 'snippet,status,recordingDetails', $init['url'] );
		$this->assertSame( 'Bearer tok', $init['headers']['Authorization'] );
		$this->assertSame( '25', $init['headers']['X-Upload-Content-Length'] );
		$this->assertSame( 'video/mp4', $init['headers']['X-Upload-Content-Type'] );
		$this->assertSame(
			[
				'snippet'          => [
					'title'                => 'Tour du Mont Blanc',
					'description'          => 'Une belle randonnée.',
					'categoryId'           => '22',
					'tags'                 => [ 'vanlife', 'Salers' ],
					'defaultLanguage'      => 'fr',
					'defaultAudioLanguage' => 'fr',
				],
				'status'           => [ 'privacyStatus' => 'private', 'selfDeclaredMadeForKids' => false, 'license' => 'creativeCommon' ],
				'recordingDetails' => [ 'recordingDate' => '2026-10-05T12:00:00Z' ],
			],
			json_decode( $init['body'], true )
		);
		$this->assertStringContainsString( 'randonnée', $init['body'] );
	}

	public function test_language_and_recording_details_are_left_out_when_unknown(): void {
		$this->uploader()->run( $this->job( [ 'language' => null, 'recording_date' => null, 'keywords' => [] ] ), 600 );

		$init = $this->server->requests[0];
		$this->assertSame( ResumableUploader::INIT_ENDPOINT . 'snippet,status', $init['url'] );
		$body = json_decode( $init['body'], true );
		$this->assertArrayNotHasKey( 'recordingDetails', $body );
		$this->assertArrayNotHasKey( 'defaultLanguage', $body['snippet'] );
		$this->assertArrayNotHasKey( 'defaultAudioLanguage', $body['snippet'] );
		$this->assertArrayNotHasKey( 'tags', $body['snippet'] );
		$this->assertSame( 'creativeCommon', $body['status']['license'] );
	}

	public function test_sends_chunks_with_content_range_headers(): void {
		$this->uploader()->run( $this->job(), 600 );

		$ranges = [];
		foreach ( array_slice( $this->server->requests, 1 ) as $request ) {
			$this->assertSame( 'PUT', $request['method'] );
			$this->assertSame( 'https://upload.example/session1', $request['url'] );
			$this->assertSame( 'video/mp4', $request['headers']['Content-Type'] );
			$ranges[] = $request['headers']['Content-Range'];
		}

		$this->assertSame( [ 'bytes 0-9/25', 'bytes 10-19/25', 'bytes 20-24/25' ], $ranges );
	}

	public function test_stops_when_the_budget_is_exhausted_and_resumes_later(): void {
		$this->server->putDelay = 10;
		$uploader               = $this->uploader();

		$job = $uploader->run( $this->job(), 15 );

		$this->assertSame( UploadJob::STATUS_UPLOADING, $job->status() );
		$this->assertSame( 20, $job->bytesSent() );
		$this->assertSame( 'https://upload.example/session1', $job->sessionUri() );

		$job = $uploader->run( $job, 15 );

		$this->assertSame( UploadJob::STATUS_DONE, $job->status() );
		$this->assertSame( self::CONTENT, $this->server->received );
		$this->assertSame( 1, $this->server->sessions );
	}

	public function test_server_error_goes_to_retry_then_resumes_at_the_position_confirmed_by_youtube(): void {
		// The session opens normally (null = default behaviour), then the first chunk gets a 503.
		$this->server->script = [ null, [ 'status' => 503, 'headers' => [], 'body' => 'busy' ] ];
		$uploader             = $this->uploader();

		$job = $uploader->run( $this->job(), 600 );

		$this->assertSame( UploadJob::STATUS_RETRY, $job->status() );
		$this->assertSame( 1, $job->attempts() );
		$this->assertSame( 1000 + 30, $job->retryAt() );
		$this->assertStringContainsString( '503', (string) $job->error() );
		$this->assertSame( 'https://upload.example/session1', $job->sessionUri() );

		$this->server->requests = [];
		$job                    = $uploader->run( $job, 600 );

		$this->assertSame( 'bytes */25', $this->server->requests[0]['headers']['Content-Range'] );
		$this->assertSame( '', $this->server->requests[0]['body'] );
		$this->assertSame( UploadJob::STATUS_DONE, $job->status() );
		$this->assertSame( self::CONTENT, $this->server->received );
	}

	public function test_transport_error_goes_to_retry(): void {
		$this->server->script = [ static function (): array {
			throw new RuntimeException( 'timeout' );
		} ];

		$job = $this->uploader()->run( $this->job(), 600 );

		$this->assertSame( UploadJob::STATUS_RETRY, $job->status() );
		$this->assertStringContainsString( 'timeout', (string) $job->error() );
		$this->assertNull( $job->sessionUri() );
	}

	public function test_backoff_doubles_and_is_capped(): void {
		$fail = [ 'status' => 503, 'headers' => [], 'body' => '' ];

		$this->server->script = [ $fail ];
		$this->assertSame( 1000 + 120, $this->uploader()->run( $this->job( [ 'attempts' => 2 ] ), 600 )->retryAt() );

		$this->server->script = [ $fail ];
		$this->assertSame( 1000 + 3600, $this->uploader()->run( $this->job( [ 'attempts' => 8 ] ), 600 )->retryAt() );
	}

	public function test_gives_up_after_too_many_attempts(): void {
		$this->server->script = [ [ 'status' => 503, 'headers' => [], 'body' => '' ] ];

		$job = $this->uploader()->run( $this->job( [ 'attempts' => ResumableUploader::MAX_ATTEMPTS - 1 ] ), 600 );

		$this->assertSame( UploadJob::STATUS_FAILED, $job->status() );
		$this->assertStringContainsString( 'Giving up', (string) $job->error() );
	}

	public function test_quota_errors_are_retried_and_other_client_errors_fail(): void {
		$quota = json_encode( [ 'error' => [ 'message' => 'Quota.', 'errors' => [ [ 'reason' => 'quotaExceeded' ] ] ] ] );
		$other = json_encode( [ 'error' => [ 'message' => 'Nope.', 'errors' => [ [ 'reason' => 'forbidden' ] ] ] ] );

		$this->server->script = [ [ 'status' => 403, 'headers' => [], 'body' => $quota ] ];
		$this->assertSame( UploadJob::STATUS_RETRY, $this->uploader()->run( $this->job(), 600 )->status() );

		$this->server->script = [ [ 'status' => 403, 'headers' => [], 'body' => $other ] ];
		$job                  = $this->uploader()->run( $this->job(), 600 );
		$this->assertSame( UploadJob::STATUS_FAILED, $job->status() );
		$this->assertStringContainsString( 'Nope.', (string) $job->error() );

		$this->server->script = [ [ 'status' => 400, 'headers' => [], 'body' => '{}' ] ];
		$this->assertSame( UploadJob::STATUS_FAILED, $this->uploader()->run( $this->job(), 600 )->status() );
	}

	public function test_an_expired_session_restarts_the_upload(): void {
		$this->server->script = [ null, [ 'status' => 404, 'headers' => [], 'body' => '' ] ];

		$job = $this->uploader()->run( $this->job(), 600 );

		$this->assertSame( UploadJob::STATUS_DONE, $job->status() );
		$this->assertSame( 2, $this->server->sessions );
		$this->assertSame( self::CONTENT, $this->server->received );
	}

	public function test_a_revoked_authorisation_fails_the_job_with_a_message(): void {
		$job = $this->uploader( [ 'status' => 'needs_reauth' ] )->run( $this->job(), 600 );

		$this->assertSame( UploadJob::STATUS_FAILED, $job->status() );
		$this->assertStringContainsString( 'Connect again', (string) $job->error() );
		$this->assertSame( [], $this->server->requests );
	}

	public function test_not_connected_fails_the_job(): void {
		$this->assertSame( UploadJob::STATUS_FAILED, $this->uploader( [] )->run( $this->job(), 600 )->status() );
	}

	public function test_unreadable_file_fails_the_job(): void {
		$job = $this->uploader( [ 'refresh_token' => 'r' ], static fn(): bool => false )->run( $this->job(), 600 );

		$this->assertSame( UploadJob::STATUS_FAILED, $job->status() );
	}

	public function test_file_that_shrank_fails_the_job(): void {
		$job = $this->uploader( [ 'refresh_token' => 'r' ], static fn( string $p, int $o, int $l ): string => 'short' )->run( $this->job(), 600 );

		$this->assertSame( UploadJob::STATUS_FAILED, $job->status() );
	}

	public function test_empty_file_fails_without_any_request(): void {
		$job = $this->uploader()->run( $this->job( [ 'size' => 0 ] ), 600 );

		$this->assertSame( UploadJob::STATUS_FAILED, $job->status() );
		$this->assertSame( [], $this->server->requests );
	}

	public function test_missing_upload_address_fails_the_job(): void {
		$this->server->script = [ [ 'status' => 200, 'headers' => [], 'body' => '' ] ];

		$this->assertSame( UploadJob::STATUS_FAILED, $this->uploader()->run( $this->job(), 600 )->status() );
	}

	public function test_final_response_without_a_video_id_fails_the_job(): void {
		$this->server->script = [ null, null, null, [ 'status' => 200, 'headers' => [], 'body' => '{}' ] ];

		$this->assertSame( UploadJob::STATUS_FAILED, $this->uploader()->run( $this->job(), 600 )->status() );
	}
}
