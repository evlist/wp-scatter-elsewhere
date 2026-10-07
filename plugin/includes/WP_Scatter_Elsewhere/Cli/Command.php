<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Cli;

use WP_CLI;
use WP_Scatter_Elsewhere\Detection\DetectedVideo;
use WP_Scatter_Elsewhere\Detection\DetectionException;
use WP_Scatter_Elsewhere\Detection\WordPressDetectorFactory;
use WP_Scatter_Elsewhere\Metadata\WordPressFactory as MetadataFactory;
use InvalidArgumentException;
use WP_Scatter_Elsewhere\Publication\LinkException;
use WP_Scatter_Elsewhere\Publication\WordPressFactory as PublicationFactory;
use WP_Scatter_Elsewhere\Thumbnails\WordPressFactory as ThumbnailFactory;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadException;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadJob;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadService;
use WP_Scatter_Elsewhere\YouTube\VideoUpdater;
use WP_Scatter_Elsewhere\YouTube\WordPressFactory;
use WP_Scatter_Elsewhere\YouTube\YouTubeConnectionException;

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

		$service = WordPressFactory::uploadService();
		$rows    = [];
		foreach ( $videos as $video ) {
			$languages = array_map( static fn( $track ) => (string) $track->language, array_filter( $video->subtitles, static fn( $track ) => $track->isUsable() ) );

			$rows[] = [
				'id'        => $video->id,
				'file'      => null !== $video->file ? basename( $video->file->path ) : implode( ' ', $video->sources ),
				'size'      => null !== $video->file ? size_format( $video->file->size ) : '',
				'subtitles' => implode( ', ', $languages ),
				'upload'    => $video->isUploadable() ? __( 'yes', 'wp-scatter-elsewhere' ) : (string) $video->reason,
				'youtube'   => $service->publicationFor( (int) $args[0], $video->id )?->youtubeId ?? '',
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
	 * [--license=<license>]
	 * : youtube (standard YouTube license) or creativeCommon (Creative Commons - Attribution). Defaults to the setting.
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
		$metadata = MetadataFactory::videoMetadataBuilder()->build( MetadataFactory::postData( $post ), isset( $assoc['license'] ) ? (string) $assoc['license'] : null );
		$service  = WordPressFactory::uploadService();

		try {
			$job = $service->enqueue( $video, $postId, $metadata, isset( $assoc['privacy'] ) ? (string) $assoc['privacy'] : null, ! empty( $assoc['force'] ) );
		} catch ( UploadException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		/* translators: 1: job id, 2: video title, 3: privacy (private, unlisted or public). */
		WP_CLI::log( sprintf( __( 'Upload %1$s created: "%2$s", privacy: %3$s.', 'wp-scatter-elsewhere' ), $job->id(), $job->title(), $job->privacy() ) );
		if ( [] !== $metadata->keywords ) {
			WP_CLI::log( sprintf( /* translators: %s: comma-separated keywords. */ __( 'Keywords: %s', 'wp-scatter-elsewhere' ), implode( ', ', $metadata->keywords ) ) );
		}
		if ( [] !== $metadata->droppedKeywords ) {
			WP_CLI::warning( sprintf( /* translators: %s: comma-separated keywords. */ __( 'Keywords left out, they do not fit the limit of YouTube: %s', 'wp-scatter-elsewhere' ), implode( ', ', $metadata->droppedKeywords ) ) );
		}
		if ( [] !== $metadata->playlists ) {
			WP_CLI::log( sprintf( /* translators: %s: comma-separated playlist IDs. */ __( 'Playlists, once uploaded: %s', 'wp-scatter-elsewhere' ), implode( ', ', $metadata->playlists ) ) );
		}
		/* translators: 1: license, 2: language code (may be empty), 3: recording date (may be empty). */
		WP_CLI::log( sprintf( __( 'License: %1$s, language: %2$s, recording date: %3$s.', 'wp-scatter-elsewhere' ), $job->license(), (string) $job->language(), (string) $job->recordingDate() ) );

		if ( ! empty( $assoc['now'] ) ) {
			$this->runNow( $service, $job->id() );
			return;
		}

		WP_CLI::success( __( 'The upload is scheduled and will run with WP-Cron.', 'wp-scatter-elsewhere' ) );
	}

	/**
	 * Links a video that is already on YouTube to a video of a post, without uploading anything.
	 *
	 * YouTube is asked for the video, which must belong to the connected channel, and the link is refused
	 * when the video is already linked to another video of the blog (use --force to move it).
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The ID of a published post.
	 *
	 * <video-id>
	 * : The ID given by the "videos" command.
	 *
	 * <youtube-url-or-id>
	 * : The web address (URL) of the video on YouTube, as copied from the browser or from the
	 * "Share" button (youtube.com/watch?v=..., youtu.be/..., youtube.com/shorts/..., embed or live),
	 * or the 11-character video ID alone. Quote the address, because of the "&" it may contain.
	 *
	 * [--force]
	 * : Ask YouTube nothing and move the link if the video is linked elsewhere.
	 *
	 * [--privacy=<privacy>]
	 * : With --force only: private, unlisted or public, when known.
	 *
	 * ## EXAMPLES
	 *
	 *     # Find the ID of the video of the post on the blog, then link it to its YouTube video.
	 *     wp scatter-elsewhere videos 57492
	 *     wp scatter-elsewhere link 57492 v41e825b7441a "https://www.youtube.com/watch?v=9_BlGDkmk7U"
	 *
	 * @param string[]              $args
	 * @param array<string, mixed> $assoc
	 */
	public function link( array $args, array $assoc ): void {
		$postId  = (int) $args[0];
		$video   = $this->selectVideo( $this->detect( $postId ), $args[1] );
		$service = PublicationFactory::linkService();

		try {
			$publication = ! empty( $assoc['force'] )
				? $service->linkUnchecked( $postId, $video->id, $args[2], isset( $assoc['privacy'] ) ? (string) $assoc['privacy'] : null )
				: $service->link( $postId, $video->id, $args[2] );
		} catch ( LinkException $e ) {
			if ( 'already_linked' === $e->errorCode() && null !== $e->linkedTo() ) {
				/* translators: 1: message, 2: post ID, 3: video ID. */
				WP_CLI::error( sprintf( __( '%1$s (post %2$d, video %3$s). Use --force to move the link.', 'wp-scatter-elsewhere' ), $e->getMessage(), $e->linkedTo()['post_id'], $e->linkedTo()['video_id'] ) );
			}

			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( sprintf( /* translators: %s: YouTube address. */ __( 'Linked to %s', 'wp-scatter-elsewhere' ), $publication->url() ) );
	}

	/**
	 * Removes the link between a video of a post and its YouTube video. Nothing is changed on YouTube.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The ID of a post.
	 *
	 * [<video-id>]
	 * : The ID given by the "videos" command. Optional when a single video is recorded for the post.
	 *
	 * @param string[] $args
	 */
	public function unlink( array $args ): void {
		$postId  = (int) $args[0];
		$videoId = $args[1] ?? $this->selectPublication( $postId, null )->videoId;

		if ( ! PublicationFactory::linkService()->unlink( $postId, $videoId ) ) {
			WP_CLI::error( __( 'No YouTube video is linked to this video.', 'wp-scatter-elsewhere' ) );
		}

		WP_CLI::success( __( 'Unlinked. Nothing was changed on YouTube.', 'wp-scatter-elsewhere' ) );
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
				'error'    => trim( (string) $job->error() . ' ' . (string) $job->warning() ),
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

	private function selectPublication( int $postId, ?string $videoId ): \WP_Scatter_Elsewhere\Publication\Publication {
		$publications = PublicationFactory::store()->forPost( $postId );

		if ( null !== $videoId ) {
			if ( ! isset( $publications[ $videoId ] ) ) {
				WP_CLI::error( __( 'No YouTube video is recorded for this video of the post. Use "upload" or "link".', 'wp-scatter-elsewhere' ) );
			}

			return $publications[ $videoId ];
		}

		if ( 1 === count( $publications ) ) {
			return reset( $publications );
		}

		if ( [] === $publications ) {
			WP_CLI::error( __( 'No YouTube video is recorded for this post. Use "upload" or "link".', 'wp-scatter-elsewhere' ) );
		}

		WP_CLI::error(
			sprintf(
				/* translators: %s: comma-separated video IDs. */
				__( 'Several YouTube videos are recorded for this post, specify one of: %s', 'wp-scatter-elsewhere' ),
				implode( ', ', array_keys( $publications ) )
			)
		);
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
