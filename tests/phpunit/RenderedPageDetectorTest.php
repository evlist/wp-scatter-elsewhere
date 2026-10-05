<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Detection\LocalFileResolver;
use WP_Scatter_Elsewhere\Detection\PageFetcher;
use WP_Scatter_Elsewhere\Detection\PageParser;
use WP_Scatter_Elsewhere\Detection\RenderedPageDetector;
use WP_Scatter_Elsewhere\Detection\UrlResolver;

class RenderedPageDetectorTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/wpse_detect_' . uniqid();
		mkdir( $this->dir . '/photos/2026/eric/10/05', 0755, true );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/photos/2026/eric/10/05/*' ) as $file ) {
			unlink( $file );
		}
		foreach ( [ 'photos/2026/eric/10/05', 'photos/2026/eric/10', 'photos/2026/eric', 'photos/2026', 'photos', '' ] as $sub ) {
			rmdir( rtrim( $this->dir . '/' . $sub, '/' ) );
		}
	}

	private function touch( string $name ): void {
		file_put_contents( $this->dir . '/photos/2026/eric/10/05/' . $name, 'data' );
	}

	private function detector( string $html, string $permalink = 'https://example.org/post/' ): RenderedPageDetector {
		return new RenderedPageDetector(
			new PageFetcher(
				static fn( int $id ): array => [ 'permalink' => $permalink, 'status' => 'publish', 'password_protected' => false, 'viewable' => true ],
				static fn( string $url ): array => [ 'status' => 200, 'body' => $html ]
			),
			new PageParser( new UrlResolver() ),
			new LocalFileResolver( 'https://e.vli.st/wp-content/uploads', $this->dir, static fn( string $url ): ?int => null )
		);
	}

	public function test_detects_the_video_and_subtitles_of_the_blog_page(): void {
		$this->touch( '20261005.mp4' );
		$this->touch( '20261005-fr.vtt' );
		$html = (string) file_get_contents( __DIR__ . '/../fixtures/grenoble-salers.html' );

		$videos = $this->detector( $html, 'https://e.vli.st/2026/10/05/grenoble-%e2%87%be-salers/' )->detect( 1 );

		$this->assertCount( 1, $videos );
		$video = $videos[0];
		$this->assertTrue( $video->isUploadable() );
		$this->assertSame( $this->dir . '/photos/2026/eric/10/05/20261005.mp4', $video->file->path );
		$this->assertSame( 'video/mp4', $video->file->mimeType );
		$this->assertCount( 1, $video->subtitles );
		$this->assertTrue( $video->subtitles[0]->isUsable() );
		$this->assertSame( 'fr', $video->subtitles[0]->language );
		$this->assertSame( 'FR', $video->subtitles[0]->label );
		$this->assertSame( $this->dir . '/photos/2026/eric/10/05/20261005-fr.vtt', $video->subtitles[0]->file->path );
		$this->assertMatchesRegularExpression( '/^v[0-9a-f]{12}$/', $video->id );
	}

	public function test_a_missing_file_makes_the_video_not_uploadable(): void {
		$html = (string) file_get_contents( __DIR__ . '/../fixtures/grenoble-salers.html' );

		$video = $this->detector( $html, 'https://e.vli.st/2026/10/05/grenoble-%e2%87%be-salers/' )->detect( 1 )[0];

		$this->assertFalse( $video->isUploadable() );
		$this->assertNotNull( $video->reason );
		$this->assertFalse( $video->subtitles[0]->isUsable() );
	}

	public function test_external_video_is_listed_but_not_uploadable(): void {
		$videos = $this->detector( '<video src="https://cdn.test/a.mp4"></video>' )->detect( 1 );

		$this->assertCount( 1, $videos );
		$this->assertFalse( $videos[0]->isUploadable() );
		$this->assertSame( [ 'https://cdn.test/a.mp4' ], $videos[0]->sources );
		$this->assertNotNull( $videos[0]->reason );
	}

	public function test_first_resolvable_source_is_used(): void {
		$this->touch( 'a.mp4' );
		$html = '<video><source src="https://cdn.test/a.webm"><source src="https://e.vli.st/wp-content/uploads/photos/2026/eric/10/05/a.mp4"></video>';

		$video = $this->detector( $html )->detect( 1 )[0];

		$this->assertTrue( $video->isUploadable() );
		$this->assertCount( 2, $video->sources );
	}

	public function test_videos_pointing_at_the_same_file_are_merged(): void {
		$this->touch( 'a.mp4' );
		$this->touch( 'a-fr.vtt' );
		$this->touch( 'a-en.vtt' );
		$base = 'https://e.vli.st/wp-content/uploads/photos/2026/eric/10/05/';
		$html = '<video src="' . $base . 'a.mp4"><track src="' . $base . 'a-fr.vtt" srclang="fr"></video>'
			. '<video src="' . $base . 'a.mp4?again"><track src="' . $base . 'a-fr.vtt" srclang="fr"><track src="' . $base . 'a-en.vtt" srclang="en"></video>';

		$videos = $this->detector( $html )->detect( 1 );

		$this->assertCount( 1, $videos );
		$this->assertSame( [ 'fr', 'en' ], array_map( static fn( $track ) => $track->language, $videos[0]->subtitles ) );
	}

	public function test_track_without_language_is_reported_as_unusable(): void {
		$this->touch( 'a.mp4' );
		$this->touch( 'a.vtt' );
		$base = 'https://e.vli.st/wp-content/uploads/photos/2026/eric/10/05/';

		$video = $this->detector( '<video src="' . $base . 'a.mp4"><track src="' . $base . 'a.vtt"></video>' )->detect( 1 )[0];

		$this->assertFalse( $video->subtitles[0]->isUsable() );
		$this->assertNotNull( $video->subtitles[0]->reason );
	}

	public function test_identity_is_stable_across_uploads_locations(): void {
		$this->touch( 'a.mp4' );
		$html = '<video src="https://e.vli.st/wp-content/uploads/photos/2026/eric/10/05/a.mp4"></video>';

		$this->assertSame( $this->detector( $html )->detect( 1 )[0]->id, $this->detector( $html )->detect( 2 )[0]->id );
	}

	public function test_page_without_video(): void {
		$this->assertSame( [], $this->detector( '<p>No video</p>' )->detect( 1 ) );
	}
}
