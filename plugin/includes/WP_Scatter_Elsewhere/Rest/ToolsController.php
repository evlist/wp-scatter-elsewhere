<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Scatter_Elsewhere\Matching\Suggestion;
use WP_Scatter_Elsewhere\Matching\WordPressFactory as MatchingFactory;
use WP_Scatter_Elsewhere\Publication\WordPressFactory as PublicationFactory;
use WP_Scatter_Elsewhere\YouTube\WordPressFactory;
use WP_Scatter_Elsewhere\YouTube\YouTubeConnectionException;

/**
 * The REST routes of the administration pages under Tools. The pages are never trusted: every route checks
 * the capability and the server decides.
 */
final class ToolsController {

	private const MAX_ITEMS = 20;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
	}

	public function registerRoutes(): void {
		$routes = [
			'/tools/link-scan'  => [ WP_REST_Server::CREATABLE, 'linkScan' ],
			'/tools/link-apply' => [ WP_REST_Server::CREATABLE, 'linkApply' ],
			'/tools/link-undo'  => [ WP_REST_Server::CREATABLE, 'linkUndo' ],
			'/tools/link-runs'  => [ WP_REST_Server::READABLE, 'linkRuns' ],
		];

		foreach ( $routes as $route => [ $methods, $callback ] ) {
			register_rest_route(
				YouTubeController::NAMESPACE,
				$route,
				[
					'methods'             => $methods,
					'callback'            => [ $this, $callback ],
					'permission_callback' => static fn(): bool => current_user_can( YouTubeController::capability() ),
				]
			);
		}
	}

	/**
	 * Examines the next posts and proposes a video of the channel for each of their videos.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function linkScan( WP_REST_Request $request ) {
		if ( ! WordPressFactory::settings()->isConnected() ) {
			return new WP_Error( 'wp_scatter_elsewhere_not_connected', __( 'The plugin is not connected to YouTube.', 'wp-scatter-elsewhere' ), [ 'status' => 400 ] );
		}

		$cursor = max( 0, (int) $request->get_param( 'cursor' ) );
		$size   = min( 10, max( 1, (int) ( $request->get_param( 'size' ) ?: 5 ) ) );
		$min    = 'suggestion' === $request->get_param( 'min_confidence' ) ? Suggestion::SUGGESTION : Suggestion::HIGH;
		$since  = (string) $request->get_param( 'since' );

		try {
			$catalog = PublicationFactory::channelCatalog()->list( 0 === $cursor && (bool) $request->get_param( 'refresh' ), '', true );
		} catch ( YouTubeConnectionException $e ) {
			return new WP_Error( 'wp_scatter_elsewhere_catalog', $e->getMessage(), [ 'status' => 502 ] );
		}

		$ids = MatchingFactory::postsWithVideo(
			null,
			1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ? $since : null,
			max( 0, (int) $request->get_param( 'limit' ) ),
			(bool) ( $request->has_param( 'unlinked_only' ) ? $request->get_param( 'unlinked_only' ) : true )
		);

		$result = MatchingFactory::scan()->chunk(
			$ids,
			$cursor,
			$size,
			static fn( int $postId ): array => MatchingFactory::examine( $postId ),
			array_map( static fn( array $row ) => $row['video'], $catalog['videos'] ),
			$min
		);

		$rows = [];
		foreach ( $result['rows'] as $row ) {
			$rows[] = [
				'post'       => $row['post'],
				'title'      => wp_strip_all_tags( get_the_title( $row['post'] ) ),
				'edit_url'   => (string) get_edit_post_link( $row['post'], 'raw' ),
				'video'      => $row['video'],
				'decision'   => $row['decision'],
				'note'       => $row['note'],
				'linked'     => $row['linked'],
				'best'       => null === $row['best'] ? null : $row['best']->video->id,
				'candidates' => array_map( [ $this, 'describe' ], $row['candidates'] ),
			];
		}

		return new WP_REST_Response(
			[
				'rows'   => $rows,
				'errors' => array_map(
					static fn( array $error ): array => [ 'post' => $error['post'], 'title' => wp_strip_all_tags( get_the_title( $error['post'] ) ), 'message' => $error['message'] ],
					$result['errors']
				),
				'next'   => $result['next'],
				'total'  => $result['total'],
				'quota'  => $this->quota(),
			]
		);
	}

	/**
	 * Links the videos that were ticked, with the checks of the editor, and logs them for the undo.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function linkApply( WP_REST_Request $request ) {
		$items = $this->items( $request->get_param( 'items' ) );
		if ( [] === $items ) {
			return new WP_Error( 'wp_scatter_elsewhere_nothing', __( 'Nothing to link.', 'wp-scatter-elsewhere' ), [ 'status' => 400 ] );
		}

		$outcomes = MatchingFactory::applier()->apply( $items );
		$linked   = array_map(
			static fn( array $outcome ): array => [ 'post' => $outcome['post'], 'video' => $outcome['video'], 'youtube' => $outcome['youtube'] ],
			array_values( array_filter( $outcomes, static fn( array $outcome ): bool => $outcome['linked'] ) )
		);
		$runId    = MatchingFactory::runLog()->add( sanitize_key( (string) $request->get_param( 'run_id' ) ), $linked );

		return new WP_REST_Response( [ 'outcomes' => $outcomes, 'run_id' => $runId, 'runs' => $this->runs() ] );
	}

	/**
	 * Undoes links of a run (all of them, or the ones given). YouTube is never changed.
	 */
	public function linkUndo( WP_REST_Request $request ): WP_REST_Response {
		$runId = sanitize_key( (string) $request->get_param( 'run_id' ) );
		$only  = $this->items( $request->get_param( 'items' ), false );
		$undone = [];

		foreach ( MatchingFactory::runLog()->runs() as $run ) {
			if ( $run['id'] !== $runId ) {
				continue;
			}

			foreach ( $run['items'] as $item ) {
				$wanted = [] === $only;
				foreach ( $only as $candidate ) {
					$wanted = $wanted || ( $candidate['post'] === $item['post'] && $candidate['video'] === $item['video'] );
				}

				// Only a link that still points at the logged YouTube video is removed.
				$publication = $wanted ? PublicationFactory::store()->get( $item['post'], $item['video'] ) : null;
				if ( null !== $publication && $publication->youtubeId === $item['youtube'] ) {
					PublicationFactory::linkService()->unlink( $item['post'], $item['video'] );
				}

				if ( $wanted ) {
					$undone[] = [ 'post' => $item['post'], 'video' => $item['video'] ];
				}
			}
		}

		MatchingFactory::runLog()->forget( $runId, $undone );

		return new WP_REST_Response( [ 'undone' => count( $undone ), 'runs' => $this->runs() ] );
	}

	public function linkRuns(): WP_REST_Response {
		return new WP_REST_Response( [ 'runs' => $this->runs() ] );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function runs(): array {
		return array_map(
			function ( array $run ): array {
				$run['items'] = array_map(
					static function ( array $item ): array {
						$item['title'] = wp_strip_all_tags( get_the_title( $item['post'] ) );

						return $item;
					},
					$run['items']
				);

				return $run;
			},
			MatchingFactory::runLog()->runs()
		);
	}

	/**
	 * @param mixed $raw
	 * @return array<int, array{post: int, video: string, youtube: string}>
	 */
	private function items( mixed $raw, bool $withYoutube = true ): array {
		$items = [];

		foreach ( is_array( $raw ) ? array_slice( $raw, 0, self::MAX_ITEMS ) : [] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$post    = (int) ( $item['post'] ?? 0 );
			$video   = sanitize_text_field( (string) ( $item['video'] ?? '' ) );
			$youtube = sanitize_text_field( (string) ( $item['youtube'] ?? '' ) );

			if ( $post > 0 && '' !== $video && ( ! $withYoutube || '' !== $youtube ) && current_user_can( 'edit_post', $post ) ) {
				$items[] = [ 'post' => $post, 'video' => $video, 'youtube' => $youtube ];
			}
		}

		return $items;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function describe( Suggestion $suggestion ): array {
		$data            = MatchingFactory::describe( $suggestion );
		$data['reasons'] = array_map( [ MatchingFactory::class, 'reasonLabel' ], $data['reasons'] );

		return $data;
	}

	/**
	 * @return array{used: int, limit: int, remaining: int, level: string, resets_at: int}
	 */
	private function quota(): array {
		$meter = WordPressFactory::quotaMeter();

		return [
			'used'      => $meter->limit() - $meter->remaining(),
			'limit'     => $meter->limit(),
			'remaining' => $meter->remaining(),
			'level'     => $meter->level(),
			'resets_at' => $meter->nextReset(),
		];
	}
}
