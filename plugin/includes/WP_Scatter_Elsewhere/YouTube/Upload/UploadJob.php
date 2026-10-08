<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube\Upload;

/**
 * The state of the upload of one video to YouTube. Immutable: with() returns a modified copy.
 */
final class UploadJob {

	public const STATUS_QUEUED    = 'queued';
	public const STATUS_UPLOADING = 'uploading';
	public const STATUS_RETRY     = 'retry';
	public const STATUS_DONE      = 'done';
	public const STATUS_FAILED    = 'failed';

	private const DEFAULTS = [
		'id'           => '',
		'post_id'      => 0,
		'video_id'     => '',
		'file_path'    => '',
		'mime_type'    => '',
		'size'         => 0,
		'title'        => '',
		'description'  => '',
		'privacy'      => 'private',
		'category_id'  => '22',
		'embeddable'   => 1,
		'public_stats' => 1,
		'made_for_kids' => 0,
		'notify'       => 1,
		'language'     => null,
		'license'      => 'youtube',
		'recording_date' => null,
		'status'       => self::STATUS_QUEUED,
		'session_uri'  => null,
		'bytes_sent'   => 0,
		'attempts'     => 0,
		'retry_at'     => 0,
		'locked_until' => 0,
		'youtube_id'   => null,
		'error'        => null,
		'warning'      => null,
		'subtitles'    => [],
		'thumbnail'    => null,
		'keywords'     => [],
		'playlists'    => [],
		'unlinked'     => 0,
		'created_at'   => 0,
		'updated_at'   => 0,
	];

	/**
	 * @param array<string, mixed> $data
	 */
	private function __construct( private array $data ) {
	}

	/**
	 * @param array<string, mixed> $data Missing or mistyped keys take their default value.
	 */
	public static function fromArray( array $data ): self {
		$clean = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			$value = $data[ $key ] ?? $default;

			if ( is_array( $default ) ) {
				$clean[ $key ] = is_array( $value ) ? $value : [];
			} elseif ( is_int( $default ) ) {
				$clean[ $key ] = (int) $value;
			} elseif ( null === $default ) {
				$clean[ $key ] = null === $value ? null : (string) $value;
			} else {
				$clean[ $key ] = (string) $value;
			}
		}

		return new self( $clean );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return $this->data;
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	public function with( array $changes ): self {
		return self::fromArray( array_merge( $this->data, $changes ) );
	}

	public function id(): string {
		return $this->data['id'];
	}

	public function postId(): int {
		return $this->data['post_id'];
	}

	public function videoId(): string {
		return $this->data['video_id'];
	}

	public function filePath(): string {
		return $this->data['file_path'];
	}

	public function mimeType(): string {
		return $this->data['mime_type'];
	}

	public function size(): int {
		return $this->data['size'];
	}

	public function title(): string {
		return $this->data['title'];
	}

	public function description(): string {
		return $this->data['description'];
	}

	public function privacy(): string {
		return $this->data['privacy'];
	}

	public function isEmbeddable(): bool {
		return 0 !== $this->data['embeddable'];
	}

	public function publicStatsViewable(): bool {
		return 0 !== $this->data['public_stats'];
	}

	public function madeForKids(): bool {
		return 0 !== $this->data['made_for_kids'];
	}

	public function notifiesSubscribers(): bool {
		return 0 !== $this->data['notify'];
	}

	public function categoryId(): string {
		return $this->data['category_id'];
	}

	public function language(): ?string {
		return $this->data['language'];
	}

	public function license(): string {
		return $this->data['license'];
	}

	public function recordingDate(): ?string {
		return $this->data['recording_date'];
	}

	public function status(): string {
		return $this->data['status'];
	}

	public function sessionUri(): ?string {
		return $this->data['session_uri'];
	}

	public function bytesSent(): int {
		return $this->data['bytes_sent'];
	}

	public function attempts(): int {
		return $this->data['attempts'];
	}

	public function retryAt(): int {
		return $this->data['retry_at'];
	}

	public function lockedUntil(): int {
		return $this->data['locked_until'];
	}

	public function youtubeId(): ?string {
		return $this->data['youtube_id'];
	}

	public function error(): ?string {
		return $this->data['error'];
	}

	/**
	 * A problem that does not make the job fail, such as subtitles that could not be sent.
	 */
	public function warning(): ?string {
		return $this->data['warning'];
	}

	/**
	 * @return array<int, array{language: string, path: string, name?: string}>
	 */
	public function subtitles(): array {
		return $this->data['subtitles'];
	}

	/**
	 * @return string[]
	 */
	public function keywords(): array {
		return $this->data['keywords'];
	}

	/**
	 * IDs of the playlists the video is put in once uploaded.
	 *
	 * @return string[]
	 */
	public function playlists(): array {
		return $this->data['playlists'];
	}

	/**
	 * Path of the image to send as thumbnail once the video is uploaded.
	 */
	public function thumbnail(): ?string {
		return $this->data['thumbnail'];
	}

	/**
	 * Whether the YouTube video of this finished job was unlinked from its video of the blog.
	 */
	public function isUnlinked(): bool {
		return 1 === $this->data['unlinked'];
	}

	public function createdAt(): int {
		return $this->data['created_at'];
	}

	public function updatedAt(): int {
		return $this->data['updated_at'];
	}

	public function isActive(): bool {
		return in_array( $this->status(), [ self::STATUS_QUEUED, self::STATUS_UPLOADING, self::STATUS_RETRY ], true );
	}
}
