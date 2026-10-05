<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Everywhere\Detection\LocalFile;
use WP_Scatter_Everywhere\Detection\LocalFileResolver;

class LocalFileResolverTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/wpse_uploads_' . uniqid();
		mkdir( $this->dir . '/photos/2026', 0755, true );
		file_put_contents( $this->dir . '/photos/2026/a b.mp4', 'video' );
		file_put_contents( $this->dir . '/photos/2026/a b-fr.vtt', 'WEBVTT' );
		file_put_contents( $this->dir . '/photos/2026/notes.txt', 'text' );
		mkdir( $this->dir . '/photos/2026/folder.mp4' );
		file_put_contents( dirname( $this->dir ) . '/secret-' . basename( $this->dir ) . '.mp4', 'secret' );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/photos/2026/*' ) as $file ) {
			is_dir( $file ) ? rmdir( $file ) : unlink( $file );
		}
		rmdir( $this->dir . '/photos/2026' );
		rmdir( $this->dir . '/photos' );
		rmdir( $this->dir );
		unlink( dirname( $this->dir ) . '/secret-' . basename( $this->dir ) . '.mp4' );
	}

	private function resolver( ?array &$lookups = null ): LocalFileResolver {
		return new LocalFileResolver(
			'https://example.org/wp-content/uploads/',
			$this->dir,
			static function ( string $url ) use ( &$lookups ): ?int {
				$lookups[] = $url;

				return str_ends_with( $url, '.mp4' ) ? 42 : null;
			}
		);
	}

	public function test_resolves_a_video_with_its_attachment(): void {
		$file = $this->resolver()->resolveVideo( 'https://example.org/wp-content/uploads/photos/2026/a%20b.mp4?v=1#t=3' );

		$this->assertInstanceOf( LocalFile::class, $file );
		$this->assertSame( $this->dir . '/photos/2026/a b.mp4', $file->path );
		$this->assertSame( 'video/mp4', $file->mimeType );
		$this->assertSame( 5, $file->size );
		$this->assertSame( 42, $file->attachmentId );
	}

	public function test_scheme_and_host_case_do_not_matter(): void {
		$file = $this->resolver()->resolveVideo( 'http://EXAMPLE.org/wp-content/uploads/photos/2026/a%20b.mp4' );

		$this->assertInstanceOf( LocalFile::class, $file );
	}

	public function test_resolves_a_subtitle_without_attachment(): void {
		$file = $this->resolver()->resolveSubtitle( '//example.org/wp-content/uploads/photos/2026/a%20b-fr.vtt' );

		$this->assertInstanceOf( LocalFile::class, $file );
		$this->assertSame( 'text/vtt', $file->mimeType );
		$this->assertNull( $file->attachmentId );
	}

	public function test_rejects_urls_outside_the_uploads_directory(): void {
		$this->assertIsString( $this->resolver()->resolveVideo( 'https://other.test/wp-content/uploads/photos/2026/a%20b.mp4' ) );
		$this->assertIsString( $this->resolver()->resolveVideo( 'https://example.org/wp-content/themes/a.mp4' ) );
		$this->assertIsString( $this->resolver()->resolveVideo( 'https://example.org/wp-content/uploads-evil/a.mp4' ) );
	}

	public function test_rejects_path_traversal(): void {
		$secret = basename( $this->dir );

		foreach ( [ '../secret-' . $secret . '.mp4', '%2e%2e/secret-' . $secret . '.mp4', 'photos/%2E%2E/%2E%2E/secret-' . $secret . '.mp4', 'photos\\..\\x.mp4' ] as $suffix ) {
			$this->assertIsString( $this->resolver()->resolveVideo( 'https://example.org/wp-content/uploads/' . $suffix ), $suffix );
		}
	}

	public function test_rejects_missing_files_directories_and_non_video_types(): void {
		$base = 'https://example.org/wp-content/uploads/photos/2026/';

		$this->assertIsString( $this->resolver()->resolveVideo( $base . 'missing.mp4' ) );
		$this->assertIsString( $this->resolver()->resolveVideo( $base . 'folder.mp4' ) );
		$this->assertIsString( $this->resolver()->resolveVideo( $base . 'notes.txt' ) );
	}

	public function test_follows_a_symbolic_link_inside_the_uploads_directory(): void {
		$target = sys_get_temp_dir() . '/wpse_mount_' . uniqid();
		mkdir( $target );
		file_put_contents( $target . '/m.mp4', 'video' );
		symlink( $target, $this->dir . '/mounted' );

		try {
			$file = $this->resolver()->resolveVideo( 'https://example.org/wp-content/uploads/mounted/m.mp4' );
			$this->assertInstanceOf( LocalFile::class, $file );
		} finally {
			unlink( $this->dir . '/mounted' );
			unlink( $target . '/m.mp4' );
			rmdir( $target );
		}
	}
}
