<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadJob;
use WP_Scatter_Elsewhere\YouTube\Upload\UploadJobStore;

class UploadJobStoreTest extends TestCase {

	/** @var array<string, array<string, mixed>> */
	private array $stored = [];

	private function store(): UploadJobStore {
		return new UploadJobStore(
			fn(): mixed => $this->stored,
			function ( array $value ): void {
				$this->stored = $value;
			}
		);
	}

	private function job( string $id, string $status = 'queued', int $post = 1, string $video = 'v1' ): UploadJob {
		return UploadJob::fromArray( [ 'id' => $id, 'status' => $status, 'post_id' => $post, 'video_id' => $video ] );
	}

	public function test_saves_and_reads_a_job(): void {
		$store = $this->store();
		$store->save( $this->job( 'a' )->with( [ 'title' => 'T', 'size' => 5, 'session_uri' => 'https://u' ] ) );

		$job = $store->get( 'a' );

		$this->assertSame( 'T', $job->title() );
		$this->assertSame( 5, $job->size() );
		$this->assertSame( 'https://u', $job->sessionUri() );
		$this->assertNull( $job->youtubeId() );
		$this->assertNull( $store->get( 'missing' ) );
	}

	public function test_saving_replaces_the_job_with_the_same_id(): void {
		$store = $this->store();
		$store->save( $this->job( 'a' ) );
		$store->save( $this->job( 'a', 'done' ) );

		$this->assertCount( 1, $store->all() );
		$this->assertSame( 'done', $store->get( 'a' )->status() );
	}

	public function test_malformed_stored_values_are_ignored(): void {
		$this->stored = [ 'x' => 'garbage', 'y' => [ 'status' => 'queued' ] ];

		$this->assertSame( [], $this->store()->all() );
	}

	public function test_finds_an_active_job_of_a_post_and_video(): void {
		$store = $this->store();
		$store->save( $this->job( 'done', 'done' ) );
		$store->save( $this->job( 'other', 'queued', 2 ) );
		$store->save( $this->job( 'retry', 'retry' ) );

		$this->assertSame( 'retry', $store->findActive( 1, 'v1' )->id() );
		$this->assertNull( $store->findActive( 1, 'v2' ) );
		$this->assertNull( $store->findActive( 3, 'v1' ) );
	}

	public function test_keeps_at_most_the_limit_dropping_the_oldest_finished_jobs_but_never_active_ones(): void {
		$store = $this->store();
		$store->save( $this->job( 'active-first', 'queued' ) );
		for ( $i = 1; $i <= UploadJobStore::MAX_JOBS; $i++ ) {
			$store->save( $this->job( 'done-' . $i, 'done' ) );
		}

		$jobs = $store->all();

		$this->assertCount( UploadJobStore::MAX_JOBS, $jobs );
		$this->assertArrayHasKey( 'active-first', $jobs );
		$this->assertArrayNotHasKey( 'done-1', $jobs );
		$this->assertArrayHasKey( 'done-' . UploadJobStore::MAX_JOBS, $jobs );
	}
}
