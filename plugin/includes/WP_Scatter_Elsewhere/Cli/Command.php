<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Cli;

use WP_CLI;
use WP_Scatter_Elsewhere\Detection\DetectedVideo;
use WP_Scatter_Elsewhere\Detection\DetectionException;
use WP_Scatter_Elsewhere\Detection\WordPressDetectorFactory;
use WP_Scatter_Elsewhere\Metadata\WordPressFactory as MetadataFactory;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadException;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadJob;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadService;
use WP_Scatter_Elsewhere\Publication\WordPressFactory as PublicationFactory;
use WP_Scatter_Elsewhere\YouTube\WordPressFactory;

/**
 * Publishes the videos of posts on YouTube.
 *
 * The first entry point of the uploads; an editor interface comes later. Videos are private unless
 * another privacy is requested or set in the settings.
 */
final class Command {

	/**
	 * Lists the videos found in the public page of a post.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The ID of a published post.
	 *
	 * @param string[] $args
	 */
	public function videos( array $args ): void {
		$videos = $this->detect( (int) $args[0] );

		if ( [] === $videos ) {
			WP_CLI::warning( __( 'No video found in the page of this post.', 'wp-scatter-elsewhere' ) );
			return;
		}

		$publications = PublicationFactory::store()->forPost( (int) $args[0] );
		$rows         = [];
		foreach ( $videos as $video ) {
			$languages = array_map( static fn( $track ) => (string) $track->language, array_filter( $video->subtitles, static fn( $track ) => $track->isUsable() ) );

			$rows[] = [
				'id'        => $video->id,
				'file'      => null !== $video->file ? basename( $video->file->path ) : implode( ' ', $video->sources ),
				'size'      => null !== $video->file ? size_format( $video->file->size ) : '',
				'subtitles' => implode( ', ', $languages ),
				'upload'    => $video->isUploadable() ? __( 'yes', 'wp-scatter-elsewhere' ) : (string) $video->reason,
				'youtube'   => isset( $publications[ $video->id ] ) ? $publications[ $video->id ]->youtubeId : '',
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'file', 'size', 'subtitles', 'upload', 'youtube' ] );
	}

	/**
	 * Creates the upload of a video of a post.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The ID of a published post.
	 *
	 * [<video-id>]
	 * : The ID given by the "videos" command. Optional when the post has a single uploadable video.
	 *
	 * [--privacy=<privacy>]
	 * : private, unlisted or public. Defaults to the setting, which is private unless changed.
	 *
	 * [--force]
	 * : Upload even if the video is already on YouTube (for example after deleting it there).
	 *
	 * [--now]
	 * : Run the upload in this terminal instead of waiting for WP-Cron.
	 *
	 * @param string[]              $args
	 * @param array<string, mixed> $assoc
	 */
	public function upload( array $args, array $assoc ): void {
		$postId = (int) $args[0];
		$post   = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			WP_CLI::error( __( 'This post does not exist.', 'wp-scatter-elsewhere' ) );
		}

		$video    = $this->selectVideo( $this->detect( $postId ), $args[1] ?? null );
		$metadata = MetadataFactory::composer()->compose( MetadataFactory::postData( $post ) );
		$service  = WordPressFactory::uploadService();

		try {
			$job = $service->enqueue( $video, $postId, $metadata['title'], $metadata['description'], isset( $assoc['privacy'] ) ? (string) $assoc['privacy'] : null, ! empty( $assoc['force'] ) );
		} catch ( UploadException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		/* translators: 1: job id, 2: video title, 3: privacy (private, unlisted or public). */
		WP_CLI::log( sprintf( __( 'Upload %1$s created: "%2$s", privacy: %3$s.', 'wp-scatter-elsewhere' ), $job->id(), $job->title(), $job->privacy() ) );

		if ( ! empty( $assoc['now'] ) ) {
			$this->runNow( $service, $job->id() );
			return;
		}

		WP_CLI::success( __( 'The upload is scheduled and will run with WP-Cron.', 'wp-scatter-elsewhere' ) );
	}

	/**
	 * Links a video that is already on YouTube to a video of a post, without uploading anything.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The ID of a published post.
	 *
	 * <video-id>
	 * : The ID given by the "videos" command.
	 *
	 * <youtube-id>
	 * : The YouTube video ID (the "v" parameter of its address).
	 *
	 * [--privacy=<privacy>]
	 * : private, unlisted or public, when known. A video of unknown privacy is treated as shareable.
	 *
	 * @param string[]              $args
	 * @param array<string, mixed> $assoc
	 */
	public function link( array $args, array $assoc ): void {
		$postId = (int) $args[0];
		$video  = $this->selectVideo( $this->detect( $postId ), $args[1] );

		try {
			$publication = WordPressFactory::uploadService()->link( $postId, $video->id, $args[2], isset( $assoc['privacy'] ) ? (string) $assoc['privacy'] : null );
		} catch ( UploadException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( sprintf( /* translators: %s: YouTube address. */ __( 'Linked to %s', 'wp-scatter-elsewhere' ), $publication->url() ) );
	}

	/**
	 * Lists the uploads and their state.
	 */
	public function jobs(): void {
		$rows = [];
		foreach ( WordPressFactory::uploadService()->jobs() as $job ) {
			$rows[] = [
				'id'       => $job->id(),
				'post'     => $job->postId(),
				'title'    => $job->title(),
				'privacy'  => $job->privacy(),
				'status'   => $job->status(),
				'progress' => $this->progress( $job ),
				'youtube'  => (string) $job->youtubeId(),
				'error'    => (string) $job->error(),
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'post', 'title', 'privacy', 'status', 'progress', 'youtube', 'error' ] );
	}

	/**
	 * Restarts a failed or waiting upload.
	 *
	 * ## OPTIONS
	 *
	 * <job-id>
	 * : The ID given by the "jobs" command.
	 *
	 * [--now]
	 * : Run the upload in this terminal instead of waiting for WP-Cron.
	 *
	 * @param string[]              $args
	 * @param array<string, mixed> $assoc
	 */
	public function retry( array $args, array $assoc ): void {
		$service = WordPressFactory::uploadService();

		try {
			$job = $service->retry( $args[0] );
		} catch ( UploadException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		if ( ! empty( $assoc['now'] ) ) {
			$this->runNow( $service, $job->id() );
			return;
		}

		WP_CLI::success( __( 'The upload is scheduled and will run with WP-Cron.', 'wp-scatter-elsewhere' ) );
	}

	/**
	 * @return DetectedVideo[]
	 */
	private function detect( int $postId ): array {
		try {
			return WordPressDetectorFactory::create()->detect( $postId );
		} catch ( DetectionException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * @param DetectedVideo[] $videos
	 */
	private function selectVideo( array $videos, ?string $videoId ): DetectedVideo {
		if ( null !== $videoId ) {
			foreach ( $videos as $video ) {
				if ( $video->id === $videoId ) {
					return $video;
				}
			}
			WP_CLI::error( __( 'No video with this ID in the page of the post. Use the "videos" command.', 'wp-scatter-elsewhere' ) );
		}

		$uploadable = array_values( array_filter( $videos, static fn( DetectedVideo $video ): bool => $video->isUploadable() ) );

		if ( 1 === count( $uploadable ) ) {
			return $uploadable[0];
		}

		if ( [] === $uploadable ) {
			WP_CLI::error( __( 'No uploadable video in the page of this post. Use the "videos" command to see why.', 'wp-scatter-elsewhere' ) );
		}

		WP_CLI::error(
			sprintf(
				/* translators: %s: comma-separated video IDs. */
				__( 'Several videos can be uploaded, specify one of: %s', 'wp-scatter-elsewhere' ),
				implode( ', ', array_map( static fn( DetectedVideo $video ): string => $video->id, $uploadable ) )
			)
		);
	}

	private function runNow( UploadService $service, string $jobId ): void {
		$job = $service->job( $jobId );

		while ( null !== $job && $job->isActive() ) {
			$previous = $job->bytesSent();
			$job      = $service->process( $jobId, 15 );

			if ( null === $job || ! $job->isActive() ) {
				break;
			}

			if ( UploadJob::STATUS_RETRY === $job->status() ) {
				$wait = max( 1, $job->retryAt() - time() );
				/* translators: 1: seconds, 2: error message. */
				WP_CLI::warning( sprintf( __( 'Waiting %1$d seconds before retrying: %2$s', 'wp-scatter-elsewhere' ), $wait, (string) $job->error() ) );
				sleep( $wait );
				continue;
			}

			if ( $job->bytesSent() === $previous ) {
				// Locked by a concurrent run: wait for it instead of spinning.
				sleep( 5 );
			}

			/* translators: %s: progress such as "40%". */
			WP_CLI::log( sprintf( __( 'Sent: %s', 'wp-scatter-elsewhere' ), $this->progress( $job ) ) );
		}

		if ( null === $job ) {
			WP_CLI::error( __( 'This upload does not exist.', 'wp-scatter-elsewhere' ) );
		}

		if ( UploadJob::STATUS_DONE === $job->status() ) {
			WP_CLI::success( sprintf( /* translators: %s: YouTube video id. */ __( 'Uploaded: https://youtu.be/%s', 'wp-scatter-elsewhere' ), (string) $job->youtubeId() ) );
			return;
		}

		WP_CLI::error( sprintf( /* translators: %s: error message. */ __( 'The upload failed: %s', 'wp-scatter-elsewhere' ), (string) $job->error() ) );
	}

	private function progress( UploadJob $job ): string {
		if ( UploadJob::STATUS_DONE === $job->status() ) {
			return '100%';
		}

		return $job->size() > 0 ? (int) floor( 100 * $job->bytesSent() / $job->size() ) . '%' : '';
	}
}
