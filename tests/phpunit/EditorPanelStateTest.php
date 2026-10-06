<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Detection\DetectedVideo;
use WP_Scatter_Elsewhere\Detection\LocalFile;
use WP_Scatter_Elsewhere\Detection\SubtitleTrack;
use WP_Scatter_Elsewhere\Editor\PostYouTubeState;
use WP_Scatter_Elsewhere\Editor\UploadRequestValidator;
use WP_Scatter_Elsewhere\Publication\Publication;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadJob;

class EditorPanelStateTest extends TestCase {

	private function video( string $id, bool $uploadable = true ): DetectedVideo {
		$file = $uploadable ? new LocalFile( '/uploads/photos/2026/' . $id . '.mp4', 'https://example.org/' . $id . '.mp4', 'video/mp4', 525 * 1048576, 12 ) : null;

		return new DetectedVideo(
			$id,
			$file,
			$uploadable ? null : 'The file is not in the uploads directory of this site.',
			[ 'https://cdn.example.org/path/' . $id . '.mp4?x=1' ],
			[
				new SubtitleTrack( 'u1', 'fr', 'FR', new LocalFile( '/u/a.vtt', 'u1', 'text/vtt', 10, null ), null ),
				new SubtitleTrack( 'u2', null, null, null, 'no language' ),
			],
			null
		);
	}

	private function job( string $status, array $extra = [] ): UploadJob {
		return UploadJob::fromArray( array_merge( [ 'id' => 'j1', 'post_id' => 7, 'video_id' => 'v1', 'status' => $status, 'size' => 1000, 'bytes_sent' => 250 ], $extra ) );
	}

	public function test_describes_a_video_without_youtube_video_or_job(): void {
		$state = ( new PostYouTubeState() )->present( [ $this->video( 'v1' ) ], [], [], 'private', 'youtube' );

		$this->assertSame( [ 'privacy' => 'private', 'license' => 'youtube' ], $state['defaults'] );
		$this->assertCount( 1, $state['videos'] );
		$video = $state['videos'][0];
		$this->assertSame( 'v1', $video['id'] );
		$this->assertSame( 'v1.mp4', $video['name'] );
		$this->assertSame( 525 * 1048576, $video['size'] );
		$this->assertTrue( $video['uploadable'] );
		$this->assertNull( $video['reason'] );
		$this->assertSame(
			[ [ 'language' => 'fr', 'label' => 'FR', 'usable' => true ], [ 'language' => null, 'label' => null, 'usable' => false ] ],
			$video['subtitles']
		);
		$this->assertNull( $video['youtube'] );
		$this->assertNull( $video['job'] );
	}

	public function test_a_video_that_cannot_be_uploaded_gets_its_name_from_its_source_and_a_reason(): void {
		$video = ( new PostYouTubeState() )->present( [ $this->video( 'v2', false ) ], [], [], 'private', 'youtube' )['videos'][0];

		$this->assertFalse( $video['uploadable'] );
		$this->assertSame( 'v2.mp4', $video['name'] );
		$this->assertNull( $video['size'] );
		$this->assertStringContainsString( 'uploads directory', (string) $video['reason'] );
	}

	public function test_a_youtube_video_is_described_with_its_address_and_privacy(): void {
		$publications = [ 'v1' => new Publication( 'v1', '9FzZpnEKL-s', 'private', 5, 'j1' ) ];

		$video = ( new PostYouTubeState() )->present( [ $this->video( 'v1' ) ], $publications, [], 'private', 'youtube' )['videos'][0];

		$this->assertSame( [ 'id' => '9FzZpnEKL-s', 'url' => 'https://www.youtube.com/watch?v=9FzZpnEKL-s', 'privacy' => 'private' ], $video['youtube'] );
	}

	public function test_an_active_job_is_described_with_its_progress(): void {
		$video = ( new PostYouTubeState() )->present( [ $this->video( 'v1' ) ], [], [ 'v1' => $this->job( 'uploading', [ 'warning' => 'w' ] ) ], 'private', 'youtube' )['videos'][0];

		$this->assertSame( [ 'id' => 'j1', 'status' => 'uploading', 'progress' => 25, 'error' => null, 'warning' => 'w', 'retry_at' => 0 ], $video['job'] );
	}

	public function test_a_failed_job_carries_its_error_and_a_done_job_is_left_to_the_youtube_video(): void {
		$state  = new PostYouTubeState();
		$failed = $state->present( [ $this->video( 'v1' ) ], [], [ 'v1' => $this->job( 'failed', [ 'error' => 'Quota.' ] ) ], 'private', 'youtube' )['videos'][0];
		$done   = $state->present( [ $this->video( 'v1' ) ], [], [ 'v1' => $this->job( 'done', [ 'bytes_sent' => 1000 ] ) ], 'private', 'youtube' )['videos'][0];

		$this->assertSame( 'failed', $failed['job']['status'] );
		$this->assertSame( 'Quota.', $failed['job']['error'] );
		$this->assertNull( $done['job'] );
	}

	public function test_progress_is_bounded_and_safe(): void {
		$state = new PostYouTubeState();

		$this->assertSame( 100, $state->statuses( [], [ 'v1' => $this->job( 'uploading', [ 'bytes_sent' => 5000 ] ) ] )['v1']['job']['progress'] );
		$this->assertSame( 0, $state->statuses( [], [ 'v1' => $this->job( 'queued', [ 'size' => 0 ] ) ] )['v1']['job']['progress'] );
	}

	public function test_statuses_cover_the_videos_that_have_a_youtube_video_or_a_job(): void {
		$statuses = ( new PostYouTubeState() )->statuses(
			[ 'v1' => new Publication( 'v1', '9FzZpnEKL-s', null, 5, null ) ],
			[ 'v2' => $this->job( 'queued', [ 'video_id' => 'v2' ] ) ]
		);

		$this->assertSame( [ 'v1', 'v2' ], array_keys( $statuses ) );
		$this->assertNotNull( $statuses['v1']['youtube'] );
		$this->assertNull( $statuses['v1']['job'] );
		$this->assertNull( $statuses['v2']['youtube'] );
		$this->assertSame( 'queued', $statuses['v2']['job']['status'] );
	}

	public function test_an_upload_request_takes_the_defaults_for_empty_values(): void {
		$result = ( new UploadRequestValidator() )->validate( [ 'video_id' => 'v1', 'privacy' => '', 'license' => '' ], [ 'v1', 'v2' ], 'private', 'creativeCommon' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( [ 'v1', 'private', 'creativeCommon' ], [ $result['video_id'], $result['privacy'], $result['license'] ] );
	}

	public function test_an_upload_request_keeps_explicit_valid_values(): void {
		$result = ( new UploadRequestValidator() )->validate( [ 'video_id' => ' v2 ', 'privacy' => 'public', 'license' => 'youtube' ], [ 'v1', 'v2' ], 'private', 'creativeCommon' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( [ 'v2', 'public', 'youtube' ], [ $result['video_id'], $result['privacy'], $result['license'] ] );
	}

	/**
	 * @dataProvider provideInvalidRequests
	 */
	public function test_an_invalid_upload_request_is_refused_with_a_message( array $params ): void {
		$result = ( new UploadRequestValidator() )->validate( $params, [ 'v1' ], 'private', 'youtube' );

		$this->assertFalse( $result['ok'] );
		$this->assertNotSame( '', $result['error'] );
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function provideInvalidRequests(): array {
		return [
			'no video'       => [ [] ],
			'unknown video'  => [ [ 'video_id' => 'other' ] ],
			'bad privacy'    => [ [ 'video_id' => 'v1', 'privacy' => 'everyone' ] ],
			'bad license'    => [ [ 'video_id' => 'v1', 'license' => 'mit' ] ],
		];
	}
}
