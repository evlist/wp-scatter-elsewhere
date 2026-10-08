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
use WP_Scatter_Elsewhere\Matching\BulkDecision;
use WP_Scatter_Elsewhere\Matching\BulkEntry;
use WP_Scatter_Elsewhere\Matching\BulkLinker;
use WP_Scatter_Elsewhere\Matching\Suggestion;
use WP_Scatter_Elsewhere\Matching\VideoMatcher;
use WP_Scatter_Elsewhere\Matching\WordPressFactory as MatchingFactory;
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
	 * Lists the videos of the connected YouTube channel, newest first, with the post they are linked to.
	 *
	 * The list is kept for an hour; reading it costs about 1 quota unit per 50 videos plus 2.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Only the videos whose title contains all these words (case and accents ignored).
	 *
	 * [--unlinked]
	 * : Leave out the videos that are already linked.
	 *
	 * [--limit=<n>]
	 * : Maximum number of videos shown.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--refresh]
	 * : Read the channel again instead of using the kept list.
	 *
	 * ## EXAMPLES
	 *
	 *     wp scatter-elsewhere channel-videos --search="grenoble salers" --unlinked
	 *
	 * @subcommand channel-videos
	 *
	 * @param string[]              $args
	 * @param array<string, mixed> $assoc
	 */
	public function channel_videos( array $args, array $assoc ): void {
		try {
			$result = PublicationFactory::channelCatalog()->list(
				! empty( $assoc['refresh'] ),
				(string) ( $assoc['search'] ?? '' ),
				! empty( $assoc['unlinked'] ),
				max( 1, (int) ( $assoc['limit'] ?? 30 ) )
			);
		} catch ( YouTubeConnectionException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		$rows = [];
		foreach ( $result['videos'] as $row ) {
			$rows[] = [
				'youtube_id' => $row['video']->id,
				'date'       => substr( $row['video']->publishedAt, 0, 10 ),
				'privacy'    => $row['video']->privacy,
				'title'      => $row['video']->title,
				'linked_to'  => null === $row['linked_to'] ? '' : $row['linked_to']['post_id'] . ' ' . $row['linked_to']['video_id'],
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'youtube_id', 'date', 'privacy', 'title', 'linked_to' ] );
		/* translators: 1: number shown, 2: number found. */
		WP_CLI::log( sprintf( __( '%1$d of %2$d videos shown.', 'wp-scatter-elsewhere' ), count( $rows ), $result['total'] ) );
	}

	/**
	 * Proposes the videos of the channel that probably belong to a post, with the reason.
	 *
	 * Nothing is linked: use "link" with the video you choose.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The ID of a post.
	 *
	 * [--refresh]
	 * : Read the channel again instead of using the kept list.
	 *
	 * @param string[]              $args
	 * @param array<string, mixed> $assoc
	 */
	public function suggest( array $args, array $assoc ): void {
		$facts = \WP_Scatter_Elsewhere\Matching\WordPressFactory::postFacts( (int) $args[0] );
		if ( null === $facts ) {
			WP_CLI::error( __( 'This post does not exist.', 'wp-scatter-elsewhere' ) );
		}

		try {
			$found = \WP_Scatter_Elsewhere\Matching\WordPressFactory::suggester()->suggest( $facts, ! empty( $assoc['refresh'] ) );
		} catch ( YouTubeConnectionException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		if ( [] === $found ) {
			WP_CLI::warning( __( 'No video of the channel seems to match this post.', 'wp-scatter-elsewhere' ) );
			return;
		}

		$rows = [];
		foreach ( $found as $suggestion ) {
			$rows[] = [
				'youtube_id' => $suggestion->video->id,
				'confidence' => $suggestion->confidence,
				'privacy'    => $suggestion->video->privacy,
				'title'      => $suggestion->video->title,
				'reason'     => implode( ', ', array_map( [ \WP_Scatter_Elsewhere\Matching\WordPressFactory::class, 'reasonLabel' ], $suggestion->reasons ) ),
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'youtube_id', 'confidence', 'privacy', 'title', 'reason' ] );
	}

	/**
	 * Links the old posts to the videos that are already on the channel, after a report.
	 *
	 * Without --apply nothing is recorded. The channel is read once; the page of each post that holds a
	 * video markup is fetched from the site (no quota) to find its videos. A YouTube video wanted by
	 * several videos of the blog is never linked automatically.
	 *
	 * ## OPTIONS
	 *
	 * [--post=<ids>]
	 * : Comma-separated post IDs to examine.
	 *
	 * [--since=<date>]
	 * : Only the posts dated on or after this date (YYYY-MM-DD).
	 *
	 * [--limit=<n>]
	 * : Examine at most this number of posts that hold a video, oldest first.
	 *
	 * [--min-confidence=<level>]
	 * : Confidence needed to link with --apply.
	 * ---
	 * default: high
	 * options:
	 *   - high
	 *   - suggestion
	 * ---
	 *
	 * [--pause=<ms>]
	 * : Pause between two posts, in milliseconds.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--apply]
	 * : Link the matches that reach the confidence. Without it, only report.
	 *
	 * [--format=<format>]
	 * : Format of the report.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp scatter-elsewhere link-existing --since=2020-01-01 --limit=20
	 *     wp scatter-elsewhere link-existing --since=2020-01-01 --limit=20 --apply
	 *
	 * @subcommand link-existing
	 *
	 * @param string[]              $args
	 * @param array<string, mixed> $assoc
	 */
	public function link_existing( array $args, array $assoc ): void {
		$min   = (string) ( $assoc['min-confidence'] ?? 'high' );
		$apply = ! empty( $assoc['apply'] );
		$pause = max( 0, (int) ( $assoc['pause'] ?? 500 ) );

		if ( ! in_array( $min, [ Suggestion::HIGH, Suggestion::SUGGESTION ], true ) ) {
			WP_CLI::error( __( 'The minimum confidence must be high or suggestion.', 'wp-scatter-elsewhere' ) );
		}

		try {
			$catalog = PublicationFactory::channelCatalog()->list( false, '', true );
		} catch ( YouTubeConnectionException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$videos = array_map( static fn( array $row ) => $row['video'], $catalog['videos'] );

		$uploads = WordPressFactory::uploadService();
		$entries = [];
		$errors  = [];
		$posts   = $this->postsToExamine( $assoc );

		foreach ( $posts as $number => $postId ) {
			if ( $number > 0 && $pause > 0 ) {
				usleep( $pause * 1000 );
			}

			$facts = MatchingFactory::postFacts( $postId );
			if ( null === $facts ) {
				continue;
			}

			try {
				$detected = WordPressDetectorFactory::create()->detect( $postId );
			} catch ( DetectionException $e ) {
				$errors[] = [ 'post' => $postId, 'video' => '', 'youtube' => '', 'confidence' => '', 'reasons' => '', 'decision' => 'page unreadable', 'note' => $e->getMessage() ];
				continue;
			}

			foreach ( $detected as $video ) {
				$entries[] = new BulkEntry( $postId, $video->id, $facts, null !== $uploads->publicationFor( $postId, $video->id ) );
			}
		}

		$decisions = ( new BulkLinker( new VideoMatcher() ) )->plan( $entries, $videos, $min );
		$rows      = $errors;
		$linked    = [];

		foreach ( $decisions as $decision ) {
			$note = $decision->note;

			if ( $apply && BulkDecision::WOULD_LINK === $decision->decision && null !== $decision->suggestion ) {
				try {
					PublicationFactory::linkService()->link( $decision->entry->postId, $decision->entry->videoId, $decision->suggestion->video->id );
					$linked[] = $decision;
					$status   = 'linked';
				} catch ( LinkException $e ) {
					$status = BulkDecision::NEEDS_DECISION;
					$note   = $e->getMessage();
				}
			} else {
				$status = $decision->decision;
			}

			$rows[] = [
				'post'       => $decision->entry->postId,
				'video'      => $decision->entry->videoId,
				'youtube'    => null === $decision->suggestion ? '' : $decision->suggestion->video->id . ' ' . $decision->suggestion->video->title,
				'confidence' => null === $decision->suggestion ? '' : $decision->suggestion->confidence,
				'reasons'    => null === $decision->suggestion ? '' : implode( ', ', array_map( [ MatchingFactory::class, 'reasonLabel' ], $decision->suggestion->reasons ) ),
				'decision'   => $status,
				'note'       => $note,
			];
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, [ 'post', 'video', 'youtube', 'confidence', 'reasons', 'decision', 'note' ] );

		foreach ( $linked as $decision ) {
			/* translators: 1: post ID, 2: video ID, 3: YouTube video ID, 4: command that undoes the link. */
			WP_CLI::log( sprintf( __( 'Linked post %1$d video %2$s to %3$s. To undo: %4$s', 'wp-scatter-elsewhere' ), $decision->entry->postId, $decision->entry->videoId, $decision->suggestion->video->id, 'wp scatter-elsewhere unlink ' . $decision->entry->postId . ' ' . $decision->entry->videoId ) );
		}

		$would = count( array_filter( $decisions, static fn( BulkDecision $d ): bool => BulkDecision::WOULD_LINK === $d->decision ) );
		WP_CLI::success(
			$apply
				/* translators: %d: number of links recorded. */
				? sprintf( __( '%d video(s) linked.', 'wp-scatter-elsewhere' ), count( $linked ) )
				/* translators: %d: number of links that --apply would record. */
				: sprintf( __( 'Nothing was recorded: %d video(s) would be linked with --apply.', 'wp-scatter-elsewhere' ), $would )
		);
	}

	/**
	 * The published posts to examine, oldest first: those whose content mentions a video.
	 *
	 * @param array<string, mixed> $assoc
	 * @return int[]
	 */
	private function postsToExamine( array $assoc ): array {
		$query = [
			'post_type'      => 'any',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];

		if ( ! empty( $assoc['post'] ) ) {
			$query['post__in'] = array_map( 'intval', explode( ',', (string) $assoc['post'] ) );
		}

		if ( ! empty( $assoc['since'] ) ) {
			$query['date_query'] = [ [ 'after' => (string) $assoc['since'], 'inclusive' => true ] ];
		}

		$limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : PHP_INT_MAX;
		$ids   = [];

		foreach ( get_posts( $query ) as $postId ) {
			if ( false !== stripos( (string) get_post_field( 'post_content', (int) $postId ), 'video' ) ) {
				$ids[] = (int) $postId;
			}

			if ( count( $ids ) >= $limit ) {
				break;
			}
		}

		return $ids;
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
	 * Sends the subtitle tracks of a video of a post to the YouTube video recorded for it.
	 *
	 * Tracks are added, or replaced when YouTube already has a standard track for the language.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The ID of a published post.
	 *
	 * [<video-id>]
	 * : The ID given by the "videos" command. Optional when a single video is recorded for the post.
	 *
	 * [--remove-auto]
	 * : Delete the automatic captions of the languages sent. Defaults to the setting.
	 *
	 * @param string[]              $args
	 * @param array<string, mixed> $assoc
	 */
	public function subtitles( array $args, array $assoc = [] ): void {
		$postId      = (int) $args[0];
		$publication = $this->selectPublication( $postId, $args[1] ?? null );
		$video       = $this->selectVideo( $this->detect( $postId ), $publication->videoId );

		$tracks = [];
		foreach ( $video->subtitles as $track ) {
			if ( $track->isUsable() ) {
				$tracks[] = [ 'language' => (string) $track->language, 'path' => $track->file->path, 'name' => (string) $track->label ];
			} else {
				WP_CLI::warning( sprintf( /* translators: 1: subtitle address, 2: reason. */ __( 'Skipped %1$s: %2$s', 'wp-scatter-elsewhere' ), $track->url, (string) $track->reason ) );
			}
		}

		if ( [] === $tracks ) {
			WP_CLI::error( __( 'No usable subtitle track in the page of this post.', 'wp-scatter-elsewhere' ) );
		}

		try {
			$result = WordPressFactory::subtitleService()->sync( $publication->youtubeId, $tracks, isset( $assoc['remove-auto'] ) ? true : null );
		} catch ( YouTubeConnectionException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		foreach ( $result->actions as $language => $action ) {
			WP_CLI::log( sprintf( /* translators: 1: language code, 2: "inserted" or "replaced". */ __( '%1$s: %2$s', 'wp-scatter-elsewhere' ), $language, $action ) );
		}

		foreach ( $result->removed as $language => $count ) {
			WP_CLI::log( sprintf( /* translators: 1: language code, 2: number of tracks. */ __( '%1$s: %2$d automatic track(s) deleted', 'wp-scatter-elsewhere' ), $language, $count ) );
		}

		if ( $result->hasErrors() ) {
			WP_CLI::error( $result->errorSummary() );
		}

		WP_CLI::success( __( 'Subtitles sent.', 'wp-scatter-elsewhere' ) );
	}

	/**
	 * Sets the featured image of a post as the thumbnail of the YouTube video recorded for it.
	 *
	 * The image is cropped to 16:9, scaled down to 1280 x 720 and compressed under 2 MB.
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
	public function thumbnail( array $args ): void {
		$postId      = (int) $args[0];
		$post        = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			WP_CLI::error( __( 'This post does not exist.', 'wp-scatter-elsewhere' ) );
		}

		$publication = $this->selectPublication( $postId, $args[1] ?? null );
		$image       = MetadataFactory::postData( $post )->featuredImagePath;

		if ( null === $image ) {
			WP_CLI::error( __( 'This post has no featured image, or its file cannot be read.', 'wp-scatter-elsewhere' ) );
		}

		try {
			ThumbnailFactory::service()->send( $publication->youtubeId, $image );
		} catch ( YouTubeConnectionException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( sprintf( /* translators: %s: YouTube address. */ __( 'Thumbnail set for %s', 'wp-scatter-elsewhere' ), $publication->url() ) );
	}

	/**
	 * Lists the caption tracks that YouTube holds for the video recorded for a post, with their state.
	 *
	 * Shows whether a track is serving, still syncing or failed (and why), whether it is a draft, and
	 * tells the tracks of the creator ("standard") from the automatic ones ("asr").
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
	public function captions( array $args ): void {
		$publication = $this->selectPublication( (int) $args[0], $args[1] ?? null );

		try {
			$tracks = WordPressFactory::captionClient()->tracks( $publication->youtubeId );
		} catch ( YouTubeConnectionException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		if ( [] === $tracks ) {
			WP_CLI::warning( __( 'YouTube holds no caption track for this video.', 'wp-scatter-elsewhere' ) );
			return;
		}

		$rows = array_map(
			static fn( array $track ): array => [
				'id'       => $track['id'],
				'language' => $track['language'],
				'name'     => $track['name'],
				'kind'     => $track['kind'],
				'status'   => $track['status'],
				'failure'  => $track['failure'],
				'draft'    => $track['draft'] ? 'yes' : 'no',
			],
			$tracks
		);

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'language', 'name', 'kind', 'status', 'failure', 'draft' ] );
	}

	/**
	 * Lists the playlists of the channel.
	 */
	public function playlists(): void {
		try {
			$playlists = WordPressFactory::playlistClient()->playlists();
		} catch ( YouTubeConnectionException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		if ( [] === $playlists ) {
			WP_CLI::warning( __( 'The channel has no playlist.', 'wp-scatter-elsewhere' ) );
			return;
		}

		$rows = [];
		foreach ( $playlists as $id => $title ) {
			$rows[] = [ 'id' => $id, 'title' => $title ];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'title' ] );
	}

	/**
	 * Puts the YouTube video recorded for a post in the playlists given by the rules of its terms.
	 *
	 * Playlists that already contain the video are skipped.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The ID of a post.
	 *
	 * [<video-id>]
	 * : The ID given by the "videos" command. Optional when a single video is recorded for the post.
	 *
	 * @subcommand playlists-add
	 *
	 * @param string[] $args
	 */
	public function playlists_add( array $args ): void {
		$postId = (int) $args[0];
		$post   = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			WP_CLI::error( __( 'This post does not exist.', 'wp-scatter-elsewhere' ) );
		}

		$publication = $this->selectPublication( $postId, $args[1] ?? null );
		$playlists   = MetadataFactory::videoMetadataBuilder()->build( MetadataFactory::postData( $post ) )->playlists;

		if ( [] === $playlists ) {
			WP_CLI::error( __( 'No rule gives a playlist to the terms of this post.', 'wp-scatter-elsewhere' ) );
		}

		$result = WordPressFactory::playlistService()->addTo( $publication->youtubeId, $playlists );

		foreach ( $result->added as $playlistId ) {
			WP_CLI::log( sprintf( /* translators: %s: playlist ID. */ __( '%s: added', 'wp-scatter-elsewhere' ), $playlistId ) );
		}
		foreach ( $result->skipped as $playlistId ) {
			WP_CLI::log( sprintf( /* translators: %s: playlist ID. */ __( '%s: already in the playlist', 'wp-scatter-elsewhere' ), $playlistId ) );
		}

		if ( $result->hasErrors() ) {
			WP_CLI::error( $result->errorSummary() );
		}

		WP_CLI::success( __( 'Playlists done.', 'wp-scatter-elsewhere' ) );
	}

	/**
	 * Applies properties of a post to the YouTube video recorded for it.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The ID of a post.
	 *
	 * [<video-id>]
	 * : The ID given by the "videos" command. Optional when a single video is recorded for the post.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list among language, license, recording_date, keywords, title and description. The keywords of the rules are added to the existing ones.
	 * ---
	 * default: language,license,recording_date
	 * ---
	 *
	 * @subcommand apply-metadata
	 *
	 * @param string[]              $args
	 * @param array<string, mixed> $assoc
	 */
	public function apply_metadata( array $args, array $assoc ): void {
		$postId = (int) $args[0];
		$post   = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			WP_CLI::error( __( 'This post does not exist.', 'wp-scatter-elsewhere' ) );
		}

		$publication = $this->selectPublication( $postId, $args[1] ?? null );
		$metadata    = MetadataFactory::videoMetadataBuilder()->build( MetadataFactory::postData( $post ) );

		$available = [
			'title'          => $metadata->title,
			'description'    => $metadata->description,
			'language'       => $metadata->language,
			'license'        => $metadata->license,
			'recording_date' => $metadata->recordingDate,
			'keywords'       => [] === $metadata->keywords ? null : $metadata->keywords,
		];

		$changes = [];
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['fields'] ?? 'language,license,recording_date' ) ) ) ) as $field ) {
			if ( ! in_array( $field, VideoUpdater::FIELDS, true ) ) {
				WP_CLI::error( sprintf( /* translators: %s: field name. */ __( 'Unknown field: %s', 'wp-scatter-elsewhere' ), $field ) );
			}
			if ( null === $available[ $field ] || '' === $available[ $field ] || [] === $available[ $field ] ) {
				WP_CLI::warning( sprintf( /* translators: %s: field name. */ __( 'No value for %s, it is left unchanged.', 'wp-scatter-elsewhere' ), $field ) );
				continue;
			}
			$changes[ $field ] = $available[ $field ];
		}

		if ( [] === $changes ) {
			WP_CLI::error( __( 'Nothing to update.', 'wp-scatter-elsewhere' ) );
		}

		try {
			$dropped = WordPressFactory::videoUpdater()->update( $publication->youtubeId, $changes );
		} catch ( InvalidArgumentException | YouTubeConnectionException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		if ( [] !== $dropped ) {
			WP_CLI::warning( sprintf( /* translators: %s: comma-separated keywords. */ __( 'Keywords left out, they do not fit the limit of YouTube: %s', 'wp-scatter-elsewhere' ), implode( ', ', $dropped ) ) );
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: YouTube address, 2: comma-separated field names. */
				__( 'Updated %1$s (%2$s).', 'wp-scatter-elsewhere' ),
				$publication->url(),
				implode( ', ', array_keys( $changes ) )
			)
		);
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
