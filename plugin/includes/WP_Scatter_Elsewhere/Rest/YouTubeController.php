<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Rest;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Scatter_Elsewhere\Detection\DetectedVideo;
use WP_Scatter_Elsewhere\Detection\DetectionException;
use WP_Scatter_Elsewhere\Detection\WordPressDetectorFactory;
use WP_Scatter_Elsewhere\Editor\PostYouTubeState;
use WP_Scatter_Elsewhere\Editor\UploadRequestValidator;
use WP_Scatter_Elsewhere\Metadata\WordPressFactory as MetadataFactory;
use WP_Scatter_Elsewhere\Publication\LinkException;
use WP_Scatter_Elsewhere\Publication\WordPressFactory as PublicationFactory;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadException;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadService;
use WP_Scatter_Elsewhere\YouTube\WordPressFactory;
use WP_Scatter_Elsewhere\YouTube\YouTubeConnectionException;

/**
 * The REST routes of the YouTube panel of the editor.
 */
final class YouTubeController {

	public const NAMESPACE = 'wp-scatter-elsewhere/v1';

	/**
	 * The capability needed to use the panel. The channel belongs to the administrator who connected it.
	 */
	public static function capability(): string {
		return (string) apply_filters( 'wp_scatter_elsewhere_capability', 'manage_options' );
	}

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
	}

	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/post/(?P<id>\d+)/youtube',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'state' ],
				'permission_callback' => [ $this, 'canUsePostPanel' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/post/(?P<id>\d+)/youtube/jobs',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'statuses' ],
				'permission_callback' => [ $this, 'canUsePostPanel' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/post/(?P<id>\d+)/youtube/upload',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'upload' ],
				'permission_callback' => [ $this, 'canUsePostPanel' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/post/(?P<id>\d+)/youtube/check',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'check' ],
				'permission_callback' => [ $this, 'canUsePostPanel' ],
			]
		);

		foreach ( [ 'link-preview' => 'linkPreview', 'link' => 'link', 'unlink' => 'unlink' ] as $route => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				'/post/(?P<id>\d+)/youtube/' . $route,
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, $callback ],
					'permission_callback' => [ $this, 'canUsePostPanel' ],
				]
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/youtube/channel-videos',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'channelVideos' ],
				'permission_callback' => static fn(): bool => current_user_can( self::capability() ),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/job/(?P<id>[A-Za-z0-9]+)/retry',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'retry' ],
				'permission_callback' => [ $this, 'canRetryJob' ],
			]
		);
	}

	public function canUsePostPanel( WP_REST_Request $request ): bool {
		return current_user_can( self::capability() ) && current_user_can( 'edit_post', (int) $request['id'] );
	}

	public function canRetryJob( WP_REST_Request $request ): bool {
		$job = WordPressFactory::uploadService()->job( (string) $request['id'] );

		return null !== $job && current_user_can( self::capability() ) && current_user_can( 'edit_post', $job->postId() );
	}

	/**
	 * The videos of the post with their state, or an explanation of why they cannot be shown.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function state( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'wp_scatter_elsewhere_no_post', __( 'This post does not exist.', 'wp-scatter-elsewhere' ), [ 'status' => 404 ] );
		}

		$settings = WordPressFactory::settings();
		$base     = [
			'connected'    => $settings->isConnected(),
			'published'    => 'publish' === $post->post_status,
			'settings_url' => admin_url( 'options-general.php?page=wp-scatter-elsewhere' ),
			'error'        => null,
			'videos'       => [],
			'defaults'     => [ 'privacy' => 'private', 'license' => 'youtube' ],
		];

		if ( ! $base['connected'] || ! $base['published'] ) {
			return new WP_REST_Response( $base );
		}

		try {
			$videos = WordPressDetectorFactory::create()->detect( $post->ID );
		} catch ( DetectionException $e ) {
			$base['error'] = $e->getMessage();

			return new WP_REST_Response( $base );
		}

		$service        = WordPressFactory::uploadService();
		$uploadSettings = WordPressFactory::uploadSettings();
		$state          = ( new PostYouTubeState() )->present(
			$videos,
			$this->publications( $service, $post->ID, array_map( static fn( DetectedVideo $video ): string => $video->id, $videos ) ),
			$service->jobsForPost( $post->ID ),
			$uploadSettings->defaultPrivacy(),
			$uploadSettings->defaultLicense()
		);

		return new WP_REST_Response( array_merge( $base, $state ) );
	}

	/**
	 * The YouTube video and upload job of each video, for the polling.
	 */
	public function statuses( WP_REST_Request $request ): WP_REST_Response {
		$postId  = (int) $request['id'];
		$service = WordPressFactory::uploadService();
		$jobs    = $service->jobsForPost( $postId );

		return new WP_REST_Response(
			[
				'videos' => ( new PostYouTubeState() )->statuses( $this->publications( $service, $postId, array_keys( $jobs ) ), $jobs ),
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'wp_scatter_elsewhere_not_published', __( 'Only a published post can be sent to YouTube.', 'wp-scatter-elsewhere' ), [ 'status' => 400 ] );
		}

		try {
			$videos = WordPressDetectorFactory::create()->detect( $post->ID );
		} catch ( DetectionException $e ) {
			return new WP_Error( 'wp_scatter_elsewhere_detection', $e->getMessage(), [ 'status' => 400 ] );
		}

		$uploadable     = array_values( array_filter( $videos, static fn( DetectedVideo $video ): bool => $video->isUploadable() ) );
		$uploadSettings = WordPressFactory::uploadSettings();
		$valid          = ( new UploadRequestValidator() )->validate(
			[
				'video_id' => $request->get_param( 'video_id' ),
				'privacy'  => $request->get_param( 'privacy' ),
				'license'  => $request->get_param( 'license' ),
			],
			array_map( static fn( DetectedVideo $video ): string => $video->id, $uploadable ),
			$uploadSettings->defaultPrivacy(),
			$uploadSettings->defaultLicense()
		);

		if ( ! $valid['ok'] ) {
			return new WP_Error( 'wp_scatter_elsewhere_invalid_request', $valid['error'], [ 'status' => 400 ] );
		}

		$video    = current( array_filter( $uploadable, static fn( DetectedVideo $candidate ): bool => $candidate->id === $valid['video_id'] ) );
		$metadata = MetadataFactory::videoMetadataBuilder()->build( MetadataFactory::postData( $post ), $valid['license'] );
		$service  = WordPressFactory::uploadService();

		try {
			$service->enqueue( $video, $post->ID, $metadata, $valid['privacy'] );
		} catch ( UploadException $e ) {
			return new WP_Error( 'wp_scatter_elsewhere_upload', $e->getMessage(), [ 'status' => 400 ] );
		}

		spawn_cron();

		return $this->statuses( $request );
	}

	/**
	 * Reads and checks a YouTube video that the author wants to link, and says where it is already linked.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function linkPreview( WP_REST_Request $request ) {
		try {
			$preview = PublicationFactory::linkService()->preview( (int) $request['id'], (string) $request->get_param( 'video_id' ), (string) $request->get_param( 'address' ) );
		} catch ( LinkException $e ) {
			return $this->linkError( $e );
		}

		return new WP_REST_Response(
			[
				'preview' => [
					'youtube_id'     => $preview->youtubeId,
					'title'          => $preview->title,
					'privacy'        => $preview->privacy,
					'published_at'   => $preview->publishedAt,
					'channel_checked' => $preview->channelChecked,
					'linked_to'      => $this->describeLink( $preview->linkedTo ),
				],
			]
		);
	}

	/**
	 * Links a YouTube video that is already there to a video of the post.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function link( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'wp_scatter_elsewhere_not_published', __( 'Only a published post can be linked to a YouTube video.', 'wp-scatter-elsewhere' ), [ 'status' => 400 ] );
		}

		$videoId = (string) $request->get_param( 'video_id' );

		try {
			$videos = WordPressDetectorFactory::create()->detect( $post->ID );
		} catch ( DetectionException $e ) {
			return new WP_Error( 'wp_scatter_elsewhere_detection', $e->getMessage(), [ 'status' => 400 ] );
		}

		if ( [] === array_filter( $videos, static fn( DetectedVideo $video ): bool => $video->id === $videoId ) ) {
			return new WP_Error( 'wp_scatter_elsewhere_invalid_request', __( 'This video is not in the page of the post.', 'wp-scatter-elsewhere' ), [ 'status' => 400 ] );
		}

		try {
			$publication = PublicationFactory::linkService()->link( $post->ID, $videoId, (string) $request->get_param( 'address' ), (bool) $request->get_param( 'confirm_move' ) );
		} catch ( LinkException $e ) {
			return $this->linkError( $e );
		}

		$status = ( new PostYouTubeState() )->statuses( [ $videoId => $publication ], [] )[ $videoId ];

		return new WP_REST_Response( [ 'videos' => [ $videoId => [ 'youtube' => $status['youtube'] ] ] ] );
	}

	/**
	 * Removes the link of a video of the post with its YouTube video. YouTube is left untouched.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function unlink( WP_REST_Request $request ) {
		$videoId = (string) $request->get_param( 'video_id' );

		if ( ! PublicationFactory::linkService()->unlink( (int) $request['id'], $videoId ) ) {
			return new WP_Error( 'wp_scatter_elsewhere_no_youtube_video', __( 'No YouTube video is linked to this video.', 'wp-scatter-elsewhere' ), [ 'status' => 404 ] );
		}

		return new WP_REST_Response( [ 'videos' => [ $videoId => [ 'youtube' => null ] ] ] );
	}

	/**
	 * Reads the real privacy of the YouTube video of a video of the post, and records it.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function check( WP_REST_Request $request ) {
		$postId      = (int) $request['id'];
		$publication = WordPressFactory::uploadService()->publicationFor( $postId, (string) $request->get_param( 'video_id' ) );

		if ( null === $publication ) {
			return new WP_Error( 'wp_scatter_elsewhere_no_youtube_video', __( 'No YouTube video is recorded for this video.', 'wp-scatter-elsewhere' ), [ 'status' => 404 ] );
		}

		try {
			$publication = PublicationFactory::refresher()->refresh( $postId, $publication, time() );
		} catch ( YouTubeConnectionException $e ) {
			return new WP_Error( 'wp_scatter_elsewhere_check', $e->getMessage(), [ 'status' => 502 ] );
		}

		// Only the YouTube video is answered: the upload job of the video, if any, must stay as it is.
		$status = ( new PostYouTubeState() )->statuses( [ $publication->videoId => $publication ], [] )[ $publication->videoId ];

		return new WP_REST_Response( [ 'videos' => [ $publication->videoId => [ 'youtube' => $status['youtube'] ] ] ] );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function retry( WP_REST_Request $request ) {
		$service = WordPressFactory::uploadService();

		try {
			$job = $service->retry( (string) $request['id'] );
		} catch ( UploadException $e ) {
			return new WP_Error( 'wp_scatter_elsewhere_retry', $e->getMessage(), [ 'status' => 400 ] );
		}

		spawn_cron();

		return new WP_REST_Response(
			[
				'videos' => ( new PostYouTubeState() )->statuses( $this->publications( $service, $job->postId(), [ $job->videoId() ] ), [ $job->videoId() => $job ] ),
			]
		);
	}

	/**
	 * The videos of the connected channel, to choose the one to link.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function channelVideos( WP_REST_Request $request ) {
		try {
			$result = PublicationFactory::channelCatalog()->list(
				(bool) $request->get_param( 'refresh' ),
				(string) $request->get_param( 'search' ),
				(bool) $request->get_param( 'unlinked' ),
				min( 100, max( 1, (int) ( $request->get_param( 'max' ) ?: 30 ) ) )
			);
		} catch ( \WP_Scatter_Elsewhere\YouTube\YouTubeConnectionException $e ) {
			return new WP_Error( 'wp_scatter_elsewhere_channel_videos', $e->getMessage(), [ 'status' => 502 ] );
		}

		$videos = [];
		foreach ( $result['videos'] as $row ) {
			$video    = $row['video'];
			$videos[] = [
				'youtube_id'   => $video->id,
				'title'        => $video->title,
				'published_at' => $video->publishedAt,
				'privacy'      => $video->privacy,
				'thumbnail'    => $video->thumbnail,
				'linked_to'    => $this->describeLink( $row['linked_to'] ),
			];
		}

		return new WP_REST_Response( [ 'videos' => $videos, 'total' => $result['total'], 'fetched_at' => $result['fetched_at'] ] );
	}

	/**
	 * @param array{post_id: int, video_id: string}|null $link
	 * @return array{post_id: int, video_id: string, title: string, edit_url: string}|null
	 */
	private function describeLink( ?array $link ): ?array {
		if ( null === $link ) {
			return null;
		}

		return [
			'post_id'  => $link['post_id'],
			'video_id' => $link['video_id'],
			'title'    => wp_strip_all_tags( get_the_title( $link['post_id'] ) ),
			'edit_url' => (string) get_edit_post_link( $link['post_id'], 'raw' ),
		];
	}

	private function linkError( LinkException $e ): WP_Error {
		$data = [ 'status' => 'already_linked' === $e->errorCode() ? 409 : 400, 'code' => $e->errorCode() ];

		return new WP_Error( 'wp_scatter_elsewhere_link_' . $e->errorCode(), $e->getMessage(), $data );
	}

	/**
	 * @param string[] $videoIds
	 * @return array<string, \WP_Scatter_Elsewhere\Publication\Publication>
	 */
	private function publications( UploadService $service, int $postId, array $videoIds ): array {
		$publications = [];
		foreach ( $videoIds as $videoId ) {
			$publication = $service->publicationFor( $postId, $videoId );
			if ( null !== $publication ) {
				$publications[ $videoId ] = $publication;
			}
		}

		return $publications;
	}
}
