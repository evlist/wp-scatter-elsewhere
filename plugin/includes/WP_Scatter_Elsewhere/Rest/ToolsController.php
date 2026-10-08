<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Rest;

use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Scatter_Elsewhere\Matching\Suggestion;
use WP_Scatter_Elsewhere\Matching\WordPressFactory as MatchingFactory;
use WP_Scatter_Elsewhere\Publication\WordPressFactory as PublicationFactory;
use WP_Scatter_Elsewhere\Update\BatchJob;
use WP_Scatter_Elsewhere\Update\BatchPlan;
use WP_Scatter_Elsewhere\Update\WordPressFactory as UpdateFactory;
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
			'/tools/update-preview' => [ WP_REST_Server::CREATABLE, 'updatePreview' ],
			'/tools/update-start'   => [ WP_REST_Server::CREATABLE, 'updateStart' ],
			'/tools/update-status'  => [ WP_REST_Server::READABLE, 'updateStatus' ],
			'/tools/update-stop'    => [ WP_REST_Server::CREATABLE, 'updateStop' ],
			'/tools/update-resume'  => [ WP_REST_Server::CREATABLE, 'updateResume' ],
			'/tools/update-log'     => [ WP_REST_Server::READABLE, 'updateLog' ],
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

	/**
	 * Reads the next videos of the selection and reports what the update would change. Nothing is sent to YouTube.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function updatePreview( WP_REST_Request $request ) {
		try {
			$plan = BatchPlan::fromArray( (array) $request->get_json_params() + (array) $request->get_body_params() );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'wp_scatter_elsewhere_plan', $e->getMessage(), [ 'status' => 400 ] );
		}

		$targets = UpdateFactory::targets( $plan );
		$cursor  = max( 0, (int) $request->get_param( 'cursor' ) );
		$slice   = array_slice( $targets, $cursor, min( 5, max( 1, (int) ( $request->get_param( 'size' ) ?: 5 ) ) ) );
		$rows    = [];
		$cost    = 0;
		$changed = 0;
		$errors  = 0;

		foreach ( $slice as $target ) {
			$report = UpdateFactory::processVideo( $plan, $target, false );
			$cost  += $report->cost;
			$errors += $report->errors;
			$changed += $report->changed() ? 1 : 0;

			foreach ( $report->rows as $row ) {
				$rows[] = array_merge( [ 'post' => $target['post'], 'title' => wp_strip_all_tags( get_the_title( $target['post'] ) ), 'youtube' => $target['youtube'] ], $row );
			}

			if ( $report->stopped ) {
				break;
			}
		}

		$next = $cursor + count( $slice );

		return new WP_REST_Response(
			[
				'rows'    => $rows,
				'examined' => count( $slice ),
				'changed' => $changed,
				'errors'  => $errors,
				'cost'    => $cost,
				'next'    => $next < count( $targets ) ? $next : null,
				'total'   => count( $targets ),
				'quota'   => $this->quota(),
			]
		);
	}

	/**
	 * Starts the update in the background. The selection is computed again here: the page is not trusted.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateStart( WP_REST_Request $request ) {
		try {
			$plan = BatchPlan::fromArray( (array) $request->get_json_params() + (array) $request->get_body_params() );
			$job  = UpdateFactory::runner()->start( $plan, UpdateFactory::targets( $plan ) );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'wp_scatter_elsewhere_plan', $e->getMessage(), [ 'status' => 400 ] );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return new WP_REST_Response( $this->updateState() );
	}

	public function updateStatus(): WP_REST_Response {
		// A job that is due is pushed along by the visit of the page, not only by the next request of the site.
		$active = UpdateFactory::store()->active();
		if ( null !== $active && $active->pauseUntil() <= time() && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return new WP_REST_Response( $this->updateState() );
	}

	public function updateStop( WP_REST_Request $request ): WP_REST_Response {
		UpdateFactory::runner()->stop( sanitize_key( (string) $request->get_param( 'job' ) ) );

		return new WP_REST_Response( $this->updateState() );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateResume( WP_REST_Request $request ) {
		try {
			UpdateFactory::runner()->resume( sanitize_key( (string) $request->get_param( 'job' ) ) );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'wp_scatter_elsewhere_busy', $e->getMessage(), [ 'status' => 409 ] );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return new WP_REST_Response( $this->updateState() );
	}

	/**
	 * The changes of a job with their previous values, for the export.
	 */
	public function updateLog( WP_REST_Request $request ): WP_REST_Response {
		$entries = array_map(
			static function ( array $entry ): array {
				$entry['title'] = wp_strip_all_tags( get_the_title( (int) ( $entry['post'] ?? 0 ) ) );

				return $entry;
			},
			UpdateFactory::store()->log( sanitize_key( (string) $request->get_param( 'job' ) ) )
		);

		return new WP_REST_Response( [ 'entries' => $entries ] );
	}

	/**
	 * @return array{jobs: array<int, array<string, mixed>>, quota: array<string, mixed>}
	 */
	private function updateState(): array {
		$jobs = array_map(
			static fn( BatchJob $job ): array => [
				'id'          => $job->id(),
				'status'      => $job->status(),
				'cursor'      => $job->cursor(),
				'total'       => $job->total(),
				'changed'     => $job->changed(),
				'unchanged'   => $job->unchanged(),
				'errors'      => $job->errors(),
				'cost'        => $job->cost(),
				'message'     => $job->message(),
				'pause_until' => $job->pauseUntil(),
				'created_at'  => $job->createdAt(),
				'plan'        => $job->plan()->toArray(),
			],
			UpdateFactory::store()->all()
		);

		return [ 'jobs' => $jobs, 'quota' => $this->quota() ];
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
