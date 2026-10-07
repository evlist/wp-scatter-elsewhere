<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Publication;

use Closure;
use WP_Scatter_Elsewhere\Settings\UploadSettings;
use WP_Scatter_Elsewhere\YouTube\VideoInspector;
use WP_Scatter_Elsewhere\YouTube\YouTubeAddress;
use WP_Scatter_Elsewhere\YouTube\YouTubeConnectionException;

/**
 * Links a video of a post to a video that is already on YouTube, after checking it, and removes links.
 * Nothing is ever changed on YouTube.
 */
final class LinkService {

	private VideoInspector $inspector;

	private PublicationStore $store;

	/**
	 * @var Closure(int, string): bool
	 */
	private Closure $unlink;

	/**
	 * @var Closure(string): ?array{post_id: int, video_id: string}
	 */
	private Closure $findLink;

	/**
	 * @var Closure(): string
	 */
	private Closure $connectedChannelId;

	/**
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * @param Closure(int, string): bool                              $unlink             Removes the link of a video of a post (true when there was one).
	 * @param Closure(string): ?array{post_id: int, video_id: string} $findLink           Where a YouTube video is linked, if anywhere.
	 * @param Closure(): string                                       $connectedChannelId Id of the connected channel, empty when unknown.
	 * @param Closure(): int                                          $clock              Current Unix time.
	 */
	public function __construct( VideoInspector $inspector, PublicationStore $store, Closure $unlink, Closure $findLink, Closure $connectedChannelId, Closure $clock ) {
		$this->inspector          = $inspector;
		$this->store              = $store;
		$this->unlink             = $unlink;
		$this->findLink           = $findLink;
		$this->connectedChannelId = $connectedChannelId;
		$this->clock              = $clock;
	}

	/**
	 * Reads and checks the video and says where it is already linked.
	 *
	 * @throws LinkException
	 */
	public function preview( int $postId, string $videoId, string $address ): LinkPreview {
		$youtubeId = $this->idOf( $address );

		try {
			$details = $this->inspector->details( $youtubeId );
		} catch ( YouTubeConnectionException $e ) {
			throw new LinkException( 'unreadable', $e->getMessage() );
		}

		$connected      = ( $this->connectedChannelId )();
		$channelChecked = '' !== $connected;

		if ( $channelChecked && $details->channelId !== $connected ) {
			throw new LinkException( 'other_channel', __( 'This video belongs to another channel than the connected one.', 'wp-scatter-elsewhere' ) );
		}

		return new LinkPreview( $youtubeId, $details->title, $details->privacy, $details->publishedAt, $channelChecked, $this->linkedElsewhere( $youtubeId, $postId, $videoId ) );
	}

	/**
	 * Links the video after the same checks as the preview.
	 *
	 * @param bool $confirmMove Whether the author agreed to take the video away from the video of the blog it is linked to.
	 * @throws LinkException With the code "already_linked" when it is linked elsewhere and $confirmMove is false.
	 */
	public function link( int $postId, string $videoId, string $address, bool $confirmMove = false ): Publication {
		$preview = $this->preview( $postId, $videoId, $address );

		if ( null !== $preview->linkedTo ) {
			if ( ! $confirmMove ) {
				throw new LinkException( 'already_linked', __( 'This YouTube video is already linked to another video of the blog.', 'wp-scatter-elsewhere' ), $preview->linkedTo );
			}

			( $this->unlink )( $preview->linkedTo['post_id'], $preview->linkedTo['video_id'] );
		}

		$uploadedAt = strtotime( $preview->publishedAt );
		$now        = ( $this->clock )();

		$publication = new Publication( $videoId, $preview->youtubeId, '' === $preview->privacy ? null : $preview->privacy, false === $uploadedAt ? $now : $uploadedAt, null, $now );
		$this->store->save( $postId, $publication );

		return $publication;
	}

	/**
	 * Links without asking YouTube anything, moving the link from wherever it was. For the cases where YouTube cannot be
	 * read or the author knows better.
	 *
	 * @param ?string $privacy Privacy of the video when known.
	 * @throws LinkException When the address or the privacy is invalid.
	 */
	public function linkUnchecked( int $postId, string $videoId, string $address, ?string $privacy = null ): Publication {
		$youtubeId = $this->idOf( $address );

		if ( null !== $privacy && ! UploadSettings::isValidPrivacy( $privacy ) ) {
			throw new LinkException( 'invalid_privacy', __( 'The privacy must be private, unlisted or public.', 'wp-scatter-elsewhere' ) );
		}

		$elsewhere = $this->linkedElsewhere( $youtubeId, $postId, $videoId );
		if ( null !== $elsewhere ) {
			( $this->unlink )( $elsewhere['post_id'], $elsewhere['video_id'] );
		}

		$publication = new Publication( $videoId, $youtubeId, $privacy, ( $this->clock )(), null );
		$this->store->save( $postId, $publication );

		return $publication;
	}

	/**
	 * Removes the link of a video of a post; true when there was one. YouTube is left untouched.
	 */
	public function unlink( int $postId, string $videoId ): bool {
		return ( $this->unlink )( $postId, $videoId );
	}

	/**
	 * @throws LinkException
	 */
	private function idOf( string $address ): string {
		$youtubeId = YouTubeAddress::extractId( $address );

		if ( null === $youtubeId ) {
			throw new LinkException( 'invalid_address', __( 'This is not the address or the ID of a YouTube video.', 'wp-scatter-elsewhere' ) );
		}

		return $youtubeId;
	}

	/**
	 * @return array{post_id: int, video_id: string}|null
	 */
	private function linkedElsewhere( string $youtubeId, int $postId, string $videoId ): ?array {
		$found = ( $this->findLink )( $youtubeId );

		if ( null === $found || ( $found['post_id'] === $postId && $found['video_id'] === $videoId ) ) {
			return null;
		}

		return $found;
	}
}
