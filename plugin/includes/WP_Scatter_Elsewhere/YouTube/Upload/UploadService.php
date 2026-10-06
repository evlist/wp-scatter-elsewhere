<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube\Upload;

use Closure;
use Throwable;
use WP_Scatter_Elsewhere\Detection\DetectedVideo;
use WP_Scatter_Elsewhere\Publication\Publication;
use WP_Scatter_Elsewhere\Publication\PublicationStore;
use WP_Scatter_Elsewhere\Playlists\PlaylistService;
use WP_Scatter_Elsewhere\Settings\UploadSettings;
use WP_Scatter_Elsewhere\Subtitles\SubtitleService;
use WP_Scatter_Elsewhere\Thumbnails\ThumbnailService;
use WP_Scatter_Elsewhere\YouTube\YouTubeConnectionException;
use WP_Scatter_Elsewhere\YouTube\VideoMetadata;

/**
 * Creates, runs, schedules and retries upload jobs. WordPress-independent: scheduling is injected.
 */
final class UploadService {

	/** Seconds added to the time budget before a running job is considered abandoned. */
	private const LOCK_MARGIN_SECONDS = 300;

	private UploadJobStore $store;

	private ResumableUploader $uploader;

	private UploadSettings $settings;

	private PublicationStore $publications;

	private SubtitleService $subtitles;

	private ThumbnailService $thumbnails;

	private PlaylistService $playlists;

	/**
	 * @var Closure(int, string): void
	 */
	private Closure $scheduler;

	/**
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * @var Closure(): string
	 */
	private Closure $idGenerator;

	/**
	 * @param Closure(int, string): void $scheduler   Schedules a run of a job at a Unix time.
	 * @param Closure(): int             $clock       Current Unix time.
	 * @param Closure(): string          $idGenerator Returns a unique job id.
	 */
	public function __construct( UploadJobStore $store, ResumableUploader $uploader, UploadSettings $settings, PublicationStore $publications, SubtitleService $subtitles, ThumbnailService $thumbnails, PlaylistService $playlists, Closure $scheduler, Closure $clock, Closure $idGenerator ) {
		$this->store       = $store;
		$this->uploader    = $uploader;
		$this->settings     = $settings;
		$this->publications = $publications;
		$this->subtitles    = $subtitles;
		$this->thumbnails   = $thumbnails;
		$this->playlists    = $playlists;
		$this->scheduler   = $scheduler;
		$this->clock       = $clock;
		$this->idGenerator = $idGenerator;
	}

	/**
	 * Creates a job and schedules its first run.
	 *
	 * @param ?string $privacy Null for the default privacy of the settings.
	 * @param bool    $force   Upload even when the video already has a YouTube video.
	 * @throws UploadException When the job cannot be created.
	 */
	public function enqueue( DetectedVideo $video, int $postId, VideoMetadata $metadata, ?string $privacy = null, bool $force = false ): UploadJob {
		$privacy ??= $this->settings->defaultPrivacy();

		if ( ! UploadSettings::isValidPrivacy( $privacy ) ) {
			throw new UploadException( __( 'The privacy must be private, unlisted or public.', 'wp-scatter-elsewhere' ) );
		}

		if ( ! UploadSettings::isValidLicense( $metadata->license ) ) {
			throw new UploadException( __( 'The license must be youtube or creativeCommon.', 'wp-scatter-elsewhere' ) );
		}

		if ( null === $video->file ) {
			throw new UploadException(
				sprintf(
					/* translators: %s: reason why the video cannot be uploaded. */
					__( 'This video cannot be uploaded: %s', 'wp-scatter-elsewhere' ),
					(string) $video->reason
				)
			);
		}

		if ( $video->file->size <= 0 ) {
			throw new UploadException( __( 'The video file is empty.', 'wp-scatter-elsewhere' ) );
		}

		if ( null !== $this->store->findActive( $postId, $video->id ) ) {
			throw new UploadException( __( 'An upload of this video is already in progress.', 'wp-scatter-elsewhere' ) );
		}

		$existing = $force ? null : $this->publicationFor( $postId, $video->id );
		if ( null !== $existing ) {
			throw new UploadException(
				sprintf(
					/* translators: %s: address of the YouTube video. */
					__( 'This video is already on YouTube: %s', 'wp-scatter-elsewhere' ),
					$existing->url()
				)
			);
		}

		$now = ( $this->clock )();
		$job = UploadJob::fromArray(
			[
				'id'          => ( $this->idGenerator )(),
				'post_id'     => $postId,
				'video_id'    => $video->id,
				'file_path'   => $video->file->path,
				'mime_type'   => $video->file->mimeType,
				'size'        => $video->file->size,
				'title'       => $metadata->title,
				'description' => $metadata->description,
				'category_id' => $metadata->categoryId,
				'language'    => $metadata->language,
				'license'     => $metadata->license,
				'recording_date' => $metadata->recordingDate,
				'privacy'     => $privacy,
				'subtitles'   => $this->subtitleTracks( $video ),
				'thumbnail'   => $metadata->thumbnailSource,
				'keywords'    => $metadata->keywords,
				'playlists'   => $metadata->playlists,
				'status'      => UploadJob::STATUS_QUEUED,
				'created_at'  => $now,
				'updated_at'  => $now,
			]
		);

		$this->store->save( $job );
		( $this->scheduler )( $now, $job->id() );

		return $job;
	}

	/**
	 * Runs a job for at most $budgetSeconds, then schedules its next run when it is not finished.
	 *
	 * @return UploadJob|null The job after the run, or null when it does not exist.
	 */
	public function process( string $jobId, int $budgetSeconds ): ?UploadJob {
		$job = $this->store->get( $jobId );
		if ( null === $job || ! $job->isActive() ) {
			return $job;
		}

		$now = ( $this->clock )();

		if ( $job->lockedUntil() > $now ) {
			return $job;
		}

		if ( UploadJob::STATUS_RETRY === $job->status() && $job->retryAt() > $now ) {
			( $this->scheduler )( $job->retryAt(), $job->id() );

			return $job;
		}

		$this->store->save( $job->with( [ 'locked_until' => $now + $budgetSeconds + self::LOCK_MARGIN_SECONDS, 'updated_at' => $now ] ) );

		try {
			$result = $this->uploader->run( $job, $budgetSeconds );
		} catch ( Throwable $e ) {
			$result = $job->with( [ 'status' => UploadJob::STATUS_RETRY, 'attempts' => $job->attempts() + 1, 'retry_at' => $now + 60, 'error' => $e->getMessage() ] );
		}

		$result = $result->with( [ 'locked_until' => 0, 'updated_at' => ( $this->clock )() ] );
		$this->store->save( $result );

		if ( UploadJob::STATUS_DONE === $result->status() && null !== $result->youtubeId() ) {
			$this->publications->save( $result->postId(), new Publication( $result->videoId(), $result->youtubeId(), $result->privacy(), ( $this->clock )(), $result->id() ) );
			$result = $this->finishUpload( $result );
		}

		if ( UploadJob::STATUS_UPLOADING === $result->status() ) {
			( $this->scheduler )( ( $this->clock )(), $result->id() );
		} elseif ( UploadJob::STATUS_RETRY === $result->status() ) {
			( $this->scheduler )( $result->retryAt(), $result->id() );
		}

		return $result;
	}

	/**
	 * Sends what goes with a finished upload: the subtitles, the thumbnail and the playlists. Best effort: the upload stays
	 * done and the problems are kept as the warning of the job.
	 */
	private function finishUpload( UploadJob $job ): UploadJob {
		if ( null === $job->youtubeId() ) {
			return $job;
		}

		$warnings = [];

		if ( [] !== $job->subtitles() ) {
			try {
				$result = $this->subtitles->sync( $job->youtubeId(), $job->subtitles() );
				if ( $result->hasErrors() ) {
					$warnings[] = sprintf(
						/* translators: %s: errors per language. */
						__( 'Subtitles: %s', 'wp-scatter-elsewhere' ),
						$result->errorSummary()
					);
				}
			} catch ( YouTubeConnectionException $e ) {
				$warnings[] = sprintf(
					/* translators: %s: error message. */
					__( 'Subtitles: %s', 'wp-scatter-elsewhere' ),
					$e->getMessage()
				);
			}
		}

		if ( null !== $job->thumbnail() ) {
			try {
				$this->thumbnails->send( $job->youtubeId(), $job->thumbnail() );
			} catch ( YouTubeConnectionException $e ) {
				$warnings[] = sprintf(
					/* translators: %s: error message. */
					__( 'Thumbnail: %s', 'wp-scatter-elsewhere' ),
					$e->getMessage()
				);
			}
		}

		if ( [] !== $job->playlists() ) {
			try {
				$result = $this->playlists->addTo( $job->youtubeId(), $job->playlists() );
				if ( $result->hasErrors() ) {
					$warnings[] = sprintf(
						/* translators: %s: errors per playlist. */
						__( 'Playlists: %s', 'wp-scatter-elsewhere' ),
						$result->errorSummary()
					);
				}
			} catch ( YouTubeConnectionException $e ) {
				$warnings[] = sprintf(
					/* translators: %s: error message. */
					__( 'Playlists: %s', 'wp-scatter-elsewhere' ),
					$e->getMessage()
				);
			}
		}

		if ( [] === $warnings ) {
			return $job;
		}

		$job = $job->with( [ 'warning' => implode( ' | ', $warnings ) ] );
		$this->store->save( $job );

		return $job;
	}

	/**
	 * The usable subtitle tracks of a video, one per language.
	 *
	 * @return array<int, array{language: string, path: string, name: string}>
	 */
	private function subtitleTracks( DetectedVideo $video ): array {
		$tracks = [];
		foreach ( $video->subtitles as $track ) {
			if ( $track->isUsable() ) {
				$tracks[ (string) $track->language ] ??= [ 'language' => (string) $track->language, 'path' => $track->file->path, 'name' => (string) $track->label ];
			}
		}

		return array_values( $tracks );
	}

	/**
	 * Restarts a failed or waiting job with a fresh attempt counter.
	 *
	 * @throws UploadException When the job does not exist or is already done.
	 */
	public function retry( string $jobId ): UploadJob {
		$job = $this->store->get( $jobId );

		if ( null === $job ) {
			throw new UploadException( __( 'This upload does not exist.', 'wp-scatter-elsewhere' ) );
		}

		if ( UploadJob::STATUS_DONE === $job->status() ) {
			throw new UploadException( __( 'This upload is already done.', 'wp-scatter-elsewhere' ) );
		}

		// A job that already has an upload session resumes it, after asking YouTube where it stands.
		$status = null !== $job->sessionUri() ? UploadJob::STATUS_RETRY : UploadJob::STATUS_QUEUED;
		$now    = ( $this->clock )();
		$fresh  = $job->with( [ 'status' => $status, 'attempts' => 0, 'retry_at' => 0, 'locked_until' => 0, 'error' => null, 'updated_at' => $now ] );

		$this->store->save( $fresh );
		( $this->scheduler )( $now, $fresh->id() );

		return $fresh;
	}

	/**
	 * The YouTube video of a video of a post: the recorded one, or else the latest completed upload job
	 * (uploads that finished before publications were recorded).
	 */
	public function publicationFor( int $postId, string $videoId ): ?Publication {
		$recorded = $this->publications->get( $postId, $videoId );
		if ( null !== $recorded ) {
			return $recorded;
		}

		$found = null;
		foreach ( $this->store->all() as $job ) {
			if ( UploadJob::STATUS_DONE === $job->status() && null !== $job->youtubeId() && $job->postId() === $postId && $job->videoId() === $videoId ) {
				$found = new Publication( $videoId, $job->youtubeId(), $job->privacy(), $job->updatedAt(), $job->id() );
			}
		}

		return $found;
	}

	/**
	 * Records an existing YouTube video as the one of a video of a post, without uploading anything.
	 *
	 * @param ?string $privacy Privacy of the video when known.
	 * @throws UploadException When the YouTube id or the privacy is invalid.
	 */
	public function link( int $postId, string $videoId, string $youtubeId, ?string $privacy = null ): Publication {
		if ( ! Publication::isValidYouTubeId( $youtubeId ) ) {
			throw new UploadException( __( 'This is not a YouTube video ID (11 letters, digits, "-" or "_").', 'wp-scatter-elsewhere' ) );
		}

		if ( null !== $privacy && ! UploadSettings::isValidPrivacy( $privacy ) ) {
			throw new UploadException( __( 'The privacy must be private, unlisted or public.', 'wp-scatter-elsewhere' ) );
		}

		$publication = new Publication( $videoId, $youtubeId, $privacy, ( $this->clock )(), null );
		$this->publications->save( $postId, $publication );

		return $publication;
	}

	/**
	 * @return array<string, UploadJob>
	 */
	public function jobs(): array {
		return $this->store->all();
	}

	public function job( string $jobId ): ?UploadJob {
		return $this->store->get( $jobId );
	}
}
