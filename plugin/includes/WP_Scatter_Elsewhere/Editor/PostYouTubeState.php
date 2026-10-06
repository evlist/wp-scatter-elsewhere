<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Editor;

use WP_Scatter_Elsewhere\Detection\DetectedVideo;
use WP_Scatter_Elsewhere\Publication\Publication;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadJob;

/**
 * Turns the videos of a post, their YouTube videos and their upload jobs into the data of the editor panel.
 */
final class PostYouTubeState {

	/**
	 * @param DetectedVideo[]            $videos
	 * @param array<string, Publication> $publications The YouTube video of each video, by detected video id.
	 * @param array<string, UploadJob>   $jobs         The latest upload job of each video, by detected video id.
	 * @return array{videos: array<int, array<string, mixed>>, defaults: array{privacy: string, license: string}}
	 */
	public function present( array $videos, array $publications, array $jobs, string $defaultPrivacy, string $defaultLicense ): array {
		$presented = [];

		foreach ( $videos as $video ) {
			$presented[] = array_merge(
				[
					'id'         => $video->id,
					'name'       => $this->name( $video ),
					'size'       => null !== $video->file ? $video->file->size : null,
					'uploadable' => $video->isUploadable(),
					'reason'     => $video->reason,
					'subtitles'  => array_map(
						static fn( $track ): array => [
							'language' => $track->language,
							'label'    => $track->label,
							'usable'   => $track->isUsable(),
						],
						$video->subtitles
					),
				],
				$this->status( $publications[ $video->id ] ?? null, $jobs[ $video->id ] ?? null )
			);
		}

		return [
			'videos'   => $presented,
			'defaults' => [ 'privacy' => $defaultPrivacy, 'license' => $defaultLicense ],
		];
	}

	/**
	 * The YouTube video and upload job of every video, for the polling that does not detect the videos again.
	 *
	 * @param array<string, Publication> $publications
	 * @param array<string, UploadJob>   $jobs
	 * @return array<string, array{youtube: ?array<string, mixed>, job: ?array<string, mixed>}>
	 */
	public function statuses( array $publications, array $jobs ): array {
		$statuses = [];

		foreach ( array_unique( array_merge( array_keys( $publications ), array_keys( $jobs ) ) ) as $videoId ) {
			$statuses[ (string) $videoId ] = $this->status( $publications[ $videoId ] ?? null, $jobs[ $videoId ] ?? null );
		}

		return $statuses;
	}

	/**
	 * @return array{youtube: ?array<string, mixed>, job: ?array<string, mixed>}
	 */
	private function status( ?Publication $publication, ?UploadJob $job ): array {
		return [
			'youtube' => null === $publication ? null : [
				'id'      => $publication->youtubeId,
				'url'     => $publication->url(),
				'privacy'    => $publication->privacy,
				'checked_at' => $publication->checkedAt,
			],
			// A finished job is told by the YouTube video; the job only matters while it is active or failed.
			'job'     => null === $job || UploadJob::STATUS_DONE === $job->status() ? null : [
				'id'       => $job->id(),
				'status'   => $job->status(),
				'progress' => $this->progress( $job ),
				'error'    => $job->error(),
				'warning'  => $job->warning(),
				'retry_at' => $job->retryAt(),
			],
		];
	}

	private function progress( UploadJob $job ): int {
		if ( $job->size() <= 0 ) {
			return 0;
		}

		return (int) min( 100, floor( 100 * $job->bytesSent() / $job->size() ) );
	}

	private function name( DetectedVideo $video ): string {
		if ( null !== $video->file ) {
			return basename( $video->file->path );
		}

		$source = $video->sources[0] ?? '';

		return '' !== $source ? basename( (string) parse_url( $source, PHP_URL_PATH ) ) : '';
	}
}
