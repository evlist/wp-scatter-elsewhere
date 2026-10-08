<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Rules\TermRule;
use WP_Scatter_Elsewhere\Update\BatchUpdater;
use WP_Scatter_Elsewhere\Update\ManagedSets;
use WP_Scatter_Elsewhere\Update\VideoDiff;
use WP_Scatter_Elsewhere\YouTube\PlaylistException;
use WP_Scatter_Elsewhere\YouTube\VideoMetadata;

class BatchUpdateTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $updates = [];

	/** @var string[] */
	private array $calls = [];

	/** @var array<string, string[]> Items by playlist for the video. */
	private array $items = [];

	private ?PlaylistException $playlistError = null;

	private function snapshot( array $overrides = [] ): array {
		return array_replace_recursive(
			[
				'snippet'          => [ 'title' => 'Titre', 'description' => "Texte\r\n", 'defaultLanguage' => 'fr', 'categoryId' => '22', 'tags' => [ 'vanlife', 'manuel' ] ],
				'status'           => [ 'privacyStatus' => 'private', 'license' => 'youtube', 'embeddable' => true, 'publicStatsViewable' => true, 'selfDeclaredMadeForKids' => false ],
				'recordingDetails' => [ 'recordingDate' => '2026-10-05T12:00:00Z' ],
			],
			$overrides
		);
	}

	private function desired( array $overrides = [] ): VideoMetadata {
		$a = array_replace(
			[ 'title' => 'Titre', 'description' => 'Texte', 'language' => 'fr', 'license' => 'youtube', 'recordingDate' => '2026-10-05T12:00:00Z', 'categoryId' => '22', 'keywords' => [ 'vanlife' ], 'playlists' => [], 'embeddable' => true, 'publicStatsViewable' => true, 'madeForKids' => false ],
			$overrides
		);

		return new VideoMetadata( $a['title'], $a['description'], $a['language'], $a['license'], $a['recordingDate'], $a['categoryId'], null, $a['keywords'], $a['playlists'], [], $a['embeddable'], $a['publicStatsViewable'], $a['madeForKids'], true );
	}

	private function updater( array $snapshot ): BatchUpdater {
		return new BatchUpdater(
			function ( string $id ) use ( $snapshot ): array {
				$this->calls[] = 'read';
				return $snapshot;
			},
			function ( string $id, array $payload ): array {
				$this->updates[] = $payload;
				return [];
			},
			function ( string $playlist, string $id ): bool {
				$this->calls[] = 'has ' . $playlist;
				if ( null !== $this->playlistError ) {
					throw $this->playlistError;
				}
				return isset( $this->items[ $playlist ] );
			},
			function ( string $playlist, string $id ): void {
				$this->calls[] = 'add ' . $playlist;
			},
			function ( string $playlist, string $id ): array {
				$this->calls[] = 'items ' . $playlist;
				return $this->items[ $playlist ] ?? [];
			},
			function ( string $item ): void {
				$this->calls[] = 'remove ' . $item;
			},
			new VideoDiff()
		);
	}

	private function process( array $snapshot, VideoMetadata $desired, array $fields, bool $apply = false, string $keywords = 'add', string $playlists = 'add', ?string $privacy = null, array $actions = [] ) {
		return $this->updater( $snapshot )->process( 'yt1', $desired, $fields, $keywords, $playlists, [ 'vanlife', 'velo' ], [ 'PLmanaged1', 'PLmanaged2' ], $privacy, $apply, $actions );
	}

	public function test_a_video_that_matches_costs_one_read_and_sends_nothing(): void {
		$report = $this->process( $this->snapshot(), $this->desired(), [ 'title', 'description', 'language', 'license', 'recording_date', 'category', 'embeddable', 'public_stats', 'made_for_kids', 'keywords' ], true );

		$this->assertFalse( $report->changed() );
		$this->assertSame( 1, $report->cost );
		$this->assertSame( [], $this->updates );
	}

	public function test_only_the_selected_fields_are_compared_and_sent(): void {
		$desired = $this->desired( [ 'license' => 'creativeCommon', 'categoryId' => '19', 'title' => 'Autre' ] );

		$report = $this->process( $this->snapshot(), $desired, [ 'license' ], true );

		$this->assertSame( [ [ 'license' => 'creativeCommon' ] ], $this->updates );
		$this->assertSame( [ [ 'field' => 'license', 'current' => 'youtube', 'new' => 'creativeCommon', 'action' => 'updated' ] ], $report->rows );
		$this->assertSame( 52, $report->cost );
	}

	public function test_without_apply_nothing_is_sent_and_the_report_says_would(): void {
		$report = $this->process( $this->snapshot(), $this->desired( [ 'categoryId' => '19', 'embeddable' => false ] ), [ 'category', 'embeddable' ] );

		$this->assertSame( [], $this->updates );
		$this->assertSame( [ 'would update', 'would update' ], array_column( $report->rows, 'action' ) );
		$this->assertSame( [ 'yes', '22' ], [ $report->rows[1]['current'], $report->rows[0]['current'] ] );
	}

	public function test_the_description_is_compared_without_line_ending_differences(): void {
		$report = $this->process( $this->snapshot(), $this->desired( [ 'description' => "Texte\n" ] ), [ 'description' ], true );
		$this->assertFalse( $report->changed() );

		$report = $this->process( $this->snapshot(), $this->desired( [ 'description' => 'Nouveau' ] ), [ 'description' ], true );
		$this->assertSame( [ [ 'description' => 'Nouveau' ] ], $this->updates );
	}

	public function test_the_recording_date_is_compared_by_day_and_sent_in_full(): void {
		$this->process( $this->snapshot(), $this->desired( [ 'recordingDate' => '2026-10-05T12:00:00Z' ] ), [ 'recording_date' ], true );
		$this->assertSame( [], $this->updates );

		$this->process( $this->snapshot(), $this->desired( [ 'recordingDate' => '2026-10-06T12:00:00Z' ] ), [ 'recording_date' ], true );
		$this->assertSame( [ [ 'recording_date' => '2026-10-06T12:00:00Z' ] ], $this->updates );
	}

	public function test_a_field_without_value_is_skipped_and_the_privacy_needs_one(): void {
		$report = $this->process( $this->snapshot(), $this->desired( [ 'language' => null ] ), [ 'language', 'privacy' ], true );

		$this->assertSame( [ 'skipped: no value', 'skipped: no value' ], array_column( $report->rows, 'action' ) );
		$this->assertSame( [], $this->updates );

		$this->process( $this->snapshot(), $this->desired(), [ 'privacy' ], true, 'add', 'add', 'public' );
		$this->assertSame( [ [ 'privacy' => 'public' ] ], $this->updates );
	}

	public function test_keywords_in_add_mode_never_remove(): void {
		$desired = $this->desired( [ 'keywords' => [ 'Salers' ] ] );

		$this->process( $this->snapshot(), $desired, [ 'keywords' ], true, 'add' );

		$this->assertSame( [ [ 'keywords' => [ 'Salers' ] ] ], $this->updates );
	}

	public function test_keywords_in_sync_mode_remove_only_the_managed_ones_that_no_longer_apply(): void {
		// "vanlife" is managed and no longer wanted, "manuel" was typed by hand and is not managed.
		$desired = $this->desired( [ 'keywords' => [ 'Salers' ] ] );

		$report = $this->process( $this->snapshot(), $desired, [ 'keywords' ], true, 'sync' );

		$this->assertSame( [ [ 'keywords' => [ 'Salers' ], 'keywords_remove' => [ 'vanlife' ] ] ], $this->updates );
		$this->assertSame( 'vanlife, manuel', $report->rows[0]['current'] );
		$this->assertSame( 'manuel, Salers', $report->rows[0]['new'] );
	}

	public function test_keywords_are_compared_ignoring_case(): void {
		$report = $this->process( $this->snapshot(), $this->desired( [ 'keywords' => [ 'VanLife' ] ] ), [ 'keywords' ], true, 'sync' );
		$this->assertFalse( $report->changed() );
	}

	public function test_playlists_in_add_mode_add_the_missing_ones_and_never_remove(): void {
		$this->items = [ 'PLhas' => [ 'i1' ], 'PLmanaged1' => [ 'i9' ] ];

		$report = $this->process( $this->snapshot(), $this->desired( [ 'playlists' => [ 'PLhas', 'PLnew' ] ] ), [ 'playlists' ], true );

		$this->assertSame( [ 'has PLhas', 'has PLnew', 'add PLnew' ], $this->calls );
		$this->assertSame( [ [ 'field' => 'playlists', 'current' => '', 'new' => 'PLnew', 'action' => 'added' ] ], $report->rows );
		$this->assertSame( 52, $report->cost );
	}

	public function test_playlists_in_sync_mode_remove_only_managed_playlists_that_no_longer_apply(): void {
		// PLmanual holds the video but no rule names it: it must not even be looked at.
		$this->items = [ 'PLhas' => [ 'i1' ], 'PLmanaged1' => [ 'i9', 'i10' ], 'PLmanual' => [ 'i5' ] ];

		$report = $this->process( $this->snapshot(), $this->desired( [ 'playlists' => [ 'PLhas' ] ] ), [ 'playlists' ], true, 'add', 'sync' );

		$this->assertSame( [ 'has PLhas', 'items PLmanaged1', 'remove i9', 'remove i10', 'items PLmanaged2' ], $this->calls );
		$this->assertSame( [ 'removed' ], array_column( $report->rows, 'action' ) );
		$this->assertSame( 'PLmanaged1', $report->rows[0]['current'] );
	}

	public function test_a_dry_run_only_reads_and_estimates_the_cost_of_applying(): void {
		$this->items = [ 'PLmanaged1' => [ 'i9' ] ];

		$report = $this->process( $this->snapshot(), $this->desired( [ 'playlists' => [ 'PLnew' ], 'categoryId' => '19' ] ), [ 'category', 'playlists' ], false, 'add', 'sync' );

		$this->assertSame( [ 'read', 'has PLnew', 'items PLmanaged1', 'items PLmanaged2' ], $this->calls );
		$this->assertSame( [], $this->updates );
		$this->assertSame( [ 'would update', 'would add', 'would remove' ], array_column( $report->rows, 'action' ) );
		// read 1 + update 51 + has 1 + add 50 + two item reads 2 + remove 50.
		$this->assertSame( 155, $report->cost );
	}

	public function test_a_quota_refusal_stops_the_run(): void {
		$this->playlistError = new PlaylistException( 'quota', true );

		$report = $this->process( $this->snapshot(), $this->desired( [ 'playlists' => [ 'PLnew', 'PLother' ] ] ), [ 'playlists', 'thumbnail' ], true, 'add', 'add', null, [ 'thumbnail' => fn(): string => 'x' ] );

		$this->assertTrue( $report->stopped );
		$this->assertSame( 1, $report->errors );
		$this->assertSame( [ 'has PLnew' ], $this->calls );
		$this->assertSame( [ 'error' ], array_column( $report->rows, 'action' ) );
	}

	public function test_another_playlist_error_does_not_stop_the_others(): void {
		$this->playlistError = new PlaylistException( 'nope' );

		$report = $this->process( $this->snapshot(), $this->desired( [ 'playlists' => [ 'PLnew', 'PLother' ] ] ), [ 'playlists' ], true );

		$this->assertFalse( $report->stopped );
		$this->assertSame( 2, $report->errors );
	}

	public function test_the_thumbnail_and_subtitle_actions_run_only_with_apply(): void {
		$done    = [];
		$actions = [
			'thumbnail' => function () use ( &$done ): string { $done[] = 'thumbnail'; return 'thumb'; },
			'subtitles' => function () use ( &$done ): string { $done[] = 'subtitles'; return 'fr'; },
		];

		$dry = $this->process( $this->snapshot(), $this->desired(), [ 'thumbnail', 'subtitles' ], false, 'add', 'add', null, $actions );
		$this->assertSame( [], $done );
		$this->assertSame( [ 'would send', 'would send' ], array_column( $dry->rows, 'action' ) );
		$this->assertSame( 450, $dry->cost );

		$this->process( $this->snapshot(), $this->desired(), [ 'thumbnail', 'subtitles' ], true, 'add', 'add', null, $actions );
		$this->assertSame( [ 'thumbnail', 'subtitles' ], $done );
	}

	public function test_an_error_of_the_video_update_is_reported_and_the_rest_goes_on(): void {
		$updater = new BatchUpdater(
			static fn( string $id ): array => [],
			static function ( string $id, array $payload ): array {
				throw new \WP_Scatter_Elsewhere\YouTube\VideoUpdateException( 'refused' );
			},
			static fn(): bool => true,
			static function (): void {},
			static fn(): array => [],
			static function (): void {},
			new VideoDiff()
		);

		$report = $updater->process( 'yt1', $this->desired(), [ 'license', 'playlists' ], 'add', 'add', [], [], null, true );

		$this->assertSame( 1, $report->errors );
		$this->assertSame( 'error', $report->rows[0]['action'] );
	}

	public function test_the_managed_sets_come_from_the_rules(): void {
		$rules = [
			new TermRule( 'category', 'a', 'PLaaaaaaaaaaa', 'Vanlife' ),
			new TermRule( 'category', 'b', 'PLaaaaaaaaaaa', 'vanlife' ),
			new TermRule( 'post_tag', 'c', '', 'Salers', false, '19' ),
			new TermRule( 'post_tag', 'd', 'PLbbbbbbbbbbb', '' ),
		];

		$this->assertSame( [ 'PLaaaaaaaaaaa', 'PLbbbbbbbbbbb' ], ManagedSets::playlists( $rules ) );
		$this->assertSame( [ 'Vanlife', 'Salers' ], ManagedSets::keywords( $rules ) );
	}
}
