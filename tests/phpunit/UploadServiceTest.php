<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Detection\DetectedVideo;
use WP_Scatter_Elsewhere\Detection\LocalFile;
use WP_Scatter_Elsewhere\Publication\PublicationStore;
use WP_Scatter_Elsewhere\Settings\UploadSettings;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;
use WP_Scatter_Elsewhere\YouTube\AccessTokenProvider;
use WP_Scatter_Elsewhere\YouTube\OAuthClient;
use WP_Scatter_Elsewhere\YouTube\Upload\ResumableUploader;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadException;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadJob;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadJobStore;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadService;
use WP_Scatter_Elsewhere\YouTube\VideoMetadata;

class UploadServiceTest extends TestCase {

	private const CONTENT = 'abcdefghijklmnopqrstuvwxy';

	/** @var array<string, array<string, mixed>> */
	private array $stored = [];

	/** @var array<int, array<string, array<string, mixed>>> Post meta by post id. */
	private array $meta = [];

	/** @var array<int, array{int, string}> */
	private array $scheduled = [];


	private int $ids = 0;

	private FakeUploadServer $server;

	/** @var array<string, mixed> */
	private array $settingsValue = [];

	protected function setUp(): void {
		$this->server = new FakeUploadServer( strlen( self::CONTENT ) );
	}

	private function service( int $chunk = 10 ): UploadService {
		$server = $this->server;
		$oauth  = new OAuthClient( 'id', 'secret', 'https://example.org/cb', static fn(): array => [ 'status' => 200, 'body' => [ 'access_token' => 'tok', 'expires_in' => 3600 ] ] );
		$tokens = new AccessTokenProvider(
			new YouTubeSettings( static fn(): array => [ 'refresh_token' => 'r', 'status' => 'connected' ], static function ( array $v ): void {} ),
			$oauth,
			static fn(): ?array => null,
			static function ( array $v, int $ttl ): void {},
			static function (): void {},
			static fn(): int => 0
		);

		return new UploadService(
			new UploadJobStore(
				fn(): mixed => $this->stored,
				function ( array $value ): void {
					$this->stored = $value;
				}
			),
			new ResumableUploader(
				fn( string $m, string $u, array $h, string $b ): array => $server->handle( $m, $u, $h, $b ),
				$tokens,
				static fn( string $p, int $o, int $l ): string => substr( self::CONTENT, $o, $l ),
				fn(): int => $this->server->now,
				$chunk
			),
			new UploadSettings(
				fn(): mixed => $this->settingsValue,
				static function ( array $v ): void {}
			),
			new PublicationStore(
				fn( int $postId ): mixed => $this->meta[ $postId ] ?? '',
				function ( int $postId, array $value ): void {
					$this->meta[ $postId ] = $value;
				}
			),
			function ( int $when, string $id ): void {
				$this->scheduled[] = [ $when, $id ];
			},
			fn(): int => $this->server->now,
			fn(): string => 'job' . ++$this->ids
		);
	}

	private function metadata( string $license = 'youtube' ): VideoMetadata {
		return new VideoMetadata( 'Title', 'Description', 'fr', $license, '2026-10-05T12:00:00Z' );
	}

	private function video( string $id = 'v1', ?LocalFile $file = null, ?string $reason = null ): DetectedVideo {
		$file ??= new LocalFile( '/uploads/a.mp4', 'https://example.org/uploads/a.mp4', 'video/mp4', strlen( self::CONTENT ), 12 );

		return new DetectedVideo( $id, $file, $reason, [ 'https://example.org/uploads/a.mp4' ], [], null );
	}

	public function test_enqueue_creates_a_private_job_and_schedules_it(): void {
		$job = $this->service()->enqueue( $this->video(), 7, $this->metadata() );

		$this->assertSame( 'job1', $job->id() );
		$this->assertSame( UploadJob::STATUS_QUEUED, $job->status() );
		$this->assertSame( 'private', $job->privacy() );
		$this->assertSame( 'Title', $job->title() );
		$this->assertSame( 'Description', $job->description() );
		$this->assertSame( 'fr', $job->language() );
		$this->assertSame( 'youtube', $job->license() );
		$this->assertSame( '2026-10-05T12:00:00Z', $job->recordingDate() );
		$this->assertSame( '22', $job->categoryId() );
		$this->assertSame( 7, $job->postId() );
		$this->assertSame( 'v1', $job->videoId() );
		$this->assertSame( '/uploads/a.mp4', $job->filePath() );
		$this->assertSame( 'video/mp4', $job->mimeType() );
		$this->assertSame( 25, $job->size() );
		$this->assertSame( [ [ 1000, 'job1' ] ], $this->scheduled );
		$this->assertSame( $job->toArray(), $this->service()->job( 'job1' )->toArray() );
	}

	public function test_enqueue_uses_the_default_privacy_of_the_settings_or_the_explicit_one(): void {
		$this->settingsValue = [ 'default_privacy' => 'unlisted' ];

		$this->assertSame( 'unlisted', $this->service()->enqueue( $this->video( 'a' ), 1, $this->metadata() )->privacy() );
		$this->assertSame( 'public', $this->service()->enqueue( $this->video( 'b' ), 1, $this->metadata(), 'public' )->privacy() );
	}

	public function test_enqueue_refuses_invalid_requests(): void {
		$service = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );

		$cases = [
			'privacy'     => fn() => $service->enqueue( $this->video( 'x' ), 7, $this->metadata(), 'everyone' ),
			'not uploadable' => fn() => $service->enqueue( new DetectedVideo( 'x', null, 'external', [], [], null ), 7, $this->metadata() ),
			'empty file'  => fn() => $service->enqueue( $this->video( 'x', new LocalFile( '/a', 'u', 'video/mp4', 0, null ) ), 7, $this->metadata() ),
			'license'     => fn() => $service->enqueue( $this->video( 'y' ), 7, $this->metadata( 'cc-by' ) ),
			'duplicate'   => fn() => $service->enqueue( $this->video(), 7, $this->metadata() ),
		];

		foreach ( $cases as $name => $call ) {
			try {
				$call();
				$this->fail( 'Expected an UploadException for ' . $name );
			} catch ( UploadException $e ) {
				$this->assertNotSame( '', $e->getMessage(), $name );
			}
		}

		$this->assertCount( 1, $service->jobs() );
	}

	public function test_a_finished_job_does_not_block_another_video_of_the_post(): void {
		$service = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );
		$service->process( 'job1', 600 );

		$this->assertSame( 'job2', $service->enqueue( $this->video( 'v2' ), 7, $this->metadata() )->id() );
	}

	public function test_a_completed_upload_is_recorded_on_the_post(): void {
		$service = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata(), 'unlisted' );

		$service->process( 'job1', 600 );

		$this->assertSame(
			[ 'v1' => [ 'youtube_id' => 'vid123', 'privacy' => 'unlisted', 'published_at' => 1000, 'job_id' => 'job1' ] ],
			$this->meta[7]
		);
	}

	public function test_an_unfinished_or_failed_upload_records_nothing(): void {
		$this->server->script = [ [ 'status' => 400, 'headers' => [], 'body' => '{}' ] ];
		$service              = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );

		$service->process( 'job1', 600 );

		$this->assertSame( [], $this->meta );
	}

	public function test_a_video_already_on_youtube_cannot_be_uploaded_again(): void {
		$service = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );
		$service->process( 'job1', 600 );

		try {
			$service->enqueue( $this->video(), 7, $this->metadata() );
			$this->fail( 'Expected an UploadException.' );
		} catch ( UploadException $e ) {
			$this->assertStringContainsString( 'https://www.youtube.com/watch?v=vid123', $e->getMessage() );
		}

		$this->assertCount( 1, $service->jobs() );
	}

	public function test_a_job_completed_before_publications_were_recorded_also_blocks_a_new_upload(): void {
		$service = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );
		$service->process( 'job1', 600 );
		$this->meta = [];

		$this->assertSame( 'vid123', $service->publicationFor( 7, 'v1' )->youtubeId );
		$this->expectException( UploadException::class );
		$service->enqueue( $this->video(), 7, $this->metadata() );
	}

	public function test_force_uploads_again_and_replaces_the_record(): void {
		$service = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );
		$service->process( 'job1', 600 );
		$this->server = new FakeUploadServer( strlen( self::CONTENT ) );
		$this->server->script = [ null, null, null, [ 'status' => 200, 'headers' => [], 'body' => '{"id":"second12345"}' ] ];
		$service = $this->service();

		$job = $service->enqueue( $this->video(), 7, $this->metadata(), null, true );
		$service->process( $job->id(), 600 );

		$this->assertSame( 'second12345', $this->meta[7]['v1']['youtube_id'] );
		$this->assertSame( 'job2', $this->meta[7]['v1']['job_id'] );
	}

	public function test_link_records_an_existing_youtube_video(): void {
		$service = $this->service();

		$publication = $service->link( 7, 'v1', 'abcDEF_-123', 'public' );

		$this->assertSame( 'https://www.youtube.com/watch?v=abcDEF_-123', $publication->url() );
		$this->assertSame( [ 'v1' => [ 'youtube_id' => 'abcDEF_-123', 'privacy' => 'public', 'published_at' => 1000, 'job_id' => null ] ], $this->meta[7] );

		$this->expectException( UploadException::class );
		$service->enqueue( $this->video(), 7, $this->metadata() );
	}

	public function test_link_rejects_invalid_ids_and_privacy(): void {
		$service = $this->service();

		foreach ( [ [ 'short', null ], [ 'has space!!', null ], [ 'abcDEF_-123', 'everyone' ] ] as [ $youtubeId, $privacy ] ) {
			try {
				$service->link( 7, 'v1', $youtubeId, $privacy );
				$this->fail( 'Expected an UploadException for ' . $youtubeId );
			} catch ( UploadException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}

		$this->assertSame( [], $this->meta );
	}

	public function test_process_uploads_and_does_not_reschedule_a_finished_job(): void {
		$service = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );
		$this->scheduled = [];

		$job = $service->process( 'job1', 600 );

		$this->assertSame( UploadJob::STATUS_DONE, $job->status() );
		$this->assertSame( 'vid123', $job->youtubeId() );
		$this->assertSame( 0, $job->lockedUntil() );
		$this->assertSame( [], $this->scheduled );
		$this->assertSame( UploadJob::STATUS_DONE, $service->job( 'job1' )->status() );
	}

	public function test_process_reschedules_an_unfinished_job_immediately(): void {
		$this->server->putDelay = 10;
		$service                = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );
		$this->scheduled = [];

		$job = $service->process( 'job1', 15 );

		$this->assertSame( UploadJob::STATUS_UPLOADING, $job->status() );
		$this->assertSame( [ [ $this->server->now, 'job1' ] ], $this->scheduled );
	}

	public function test_process_reschedules_a_job_in_retry_at_its_retry_time(): void {
		$this->server->script = [ null, [ 'status' => 503, 'headers' => [], 'body' => '' ] ];
		$service              = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );
		$this->scheduled = [];

		$job = $service->process( 'job1', 600 );

		$this->assertSame( UploadJob::STATUS_RETRY, $job->status() );
		$this->assertSame( [ [ 1030, 'job1' ] ], $this->scheduled );
	}

	public function test_process_postpones_a_retry_whose_delay_has_not_elapsed(): void {
		$this->server->script = [ null, [ 'status' => 503, 'headers' => [], 'body' => '' ] ];
		$service              = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );
		$service->process( 'job1', 600 );
		$this->scheduled = [];
		$this->server->requests = [];
		$this->server->now = 1010;

		$job = $service->process( 'job1', 600 );

		$this->assertSame( UploadJob::STATUS_RETRY, $job->status() );
		$this->assertSame( [], $this->server->requests );
		$this->assertSame( [ [ 1030, 'job1' ] ], $this->scheduled );

		$this->server->now = 1030;
		$this->assertSame( UploadJob::STATUS_DONE, $service->process( 'job1', 600 )->status() );
	}

	public function test_a_locked_job_is_not_run_twice(): void {
		$service = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );
		$this->stored['job1']['locked_until'] = $this->server->now + 100;

		$job = $service->process( 'job1', 600 );

		$this->assertSame( UploadJob::STATUS_QUEUED, $job->status() );
		$this->assertSame( [], $this->server->requests );

		$this->server->now = 1101;
		$this->assertSame( UploadJob::STATUS_DONE, $service->process( 'job1', 600 )->status() );
	}

	public function test_the_job_is_locked_during_the_run(): void {
		$service = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );
		$seen = null;
		$this->server->script = [ function () use ( &$seen ): array {
			$seen = $this->stored['job1']['locked_until'];

			return [ 'status' => 200, 'headers' => [ 'location' => 'https://upload.example/session1' ], 'body' => '' ];
		} ];

		$service->process( 'job1', 600 );

		$this->assertSame( 1000 + 600 + 300, $seen );
	}

	public function test_process_ignores_unknown_and_finished_jobs(): void {
		$service = $this->service();

		$this->assertNull( $service->process( 'nope', 600 ) );

		$service->enqueue( $this->video(), 7, $this->metadata() );
		$service->process( 'job1', 600 );
		$this->server->requests = [];

		$this->assertSame( UploadJob::STATUS_DONE, $service->process( 'job1', 600 )->status() );
		$this->assertSame( [], $this->server->requests );
	}

	public function test_an_unexpected_exception_puts_the_job_in_retry(): void {
		$this->server->script = [ static function (): array {
			throw new LogicException( 'bug' );
		} ];
		$service = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );

		$job = $service->process( 'job1', 600 );

		$this->assertSame( UploadJob::STATUS_RETRY, $job->status() );
		$this->assertSame( 'bug', $job->error() );
		$this->assertSame( 0, $job->lockedUntil() );
	}

	public function test_retry_restarts_a_failed_job_with_a_fresh_counter(): void {
		$this->server->script = [ [ 'status' => 400, 'headers' => [], 'body' => '{}' ] ];
		$service              = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );
		$failed = $service->process( 'job1', 600 );
		$this->assertSame( UploadJob::STATUS_FAILED, $failed->status() );
		$this->scheduled = [];

		$job = $service->retry( 'job1' );

		$this->assertSame( UploadJob::STATUS_QUEUED, $job->status() );
		$this->assertSame( 0, $job->attempts() );
		$this->assertNull( $job->error() );
		$this->assertSame( [ [ 1000, 'job1' ] ], $this->scheduled );
		$this->assertSame( UploadJob::STATUS_DONE, $service->process( 'job1', 600 )->status() );
	}

	public function test_retry_of_a_job_with_a_session_resumes_after_asking_youtube(): void {
		$this->server->script = [ null, [ 'status' => 400, 'headers' => [], 'body' => '{}' ] ];
		$service              = $this->service();
		$service->enqueue( $this->video(), 7, $this->metadata() );
		$service->process( 'job1', 600 );
		$this->stored['job1']['session_uri'] = 'https://upload.example/session1';
		$this->server->requests              = [];

		$job = $service->retry( 'job1' );

		$this->assertSame( UploadJob::STATUS_RETRY, $job->status() );
		$service->process( 'job1', 600 );
		$this->assertSame( 'bytes */25', $this->server->requests[0]['headers']['Content-Range'] );
	}

	public function test_retry_refuses_unknown_and_done_jobs(): void {
		$service = $this->service();

		try {
			$service->retry( 'nope' );
			$this->fail( 'Expected an UploadException.' );
		} catch ( UploadException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}

		$service->enqueue( $this->video(), 7, $this->metadata() );
		$service->process( 'job1', 600 );

		$this->expectException( UploadException::class );
		$service->retry( 'job1' );
	}
}
