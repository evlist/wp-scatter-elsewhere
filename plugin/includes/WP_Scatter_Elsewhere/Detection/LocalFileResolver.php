<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Detection;

use Closure;

/**
 * Maps a URL of the uploads directory to a local file.
 */
final class LocalFileResolver {

	private const VIDEO_TYPES = [
		'mp4'  => 'video/mp4',
		'm4v'  => 'video/x-m4v',
		'mov'  => 'video/quicktime',
		'webm' => 'video/webm',
		'mkv'  => 'video/x-matroska',
		'avi'  => 'video/x-msvideo',
		'mpg'  => 'video/mpeg',
		'mpeg' => 'video/mpeg',
		'ogv'  => 'video/ogg',
		'wmv'  => 'video/x-ms-wmv',
		'flv'  => 'video/x-flv',
		'3gp'  => 'video/3gpp',
	];

	private const OTHER_TYPES = [
		'vtt' => 'text/vtt',
		'srt' => 'application/x-subrip',
	];

	private string $baseUrl;

	private string $baseDir;

	/**
	 * @var Closure(string): ?int
	 */
	private Closure $attachmentLookup;

	/**
	 * @param string                $baseUrl          Uploads base URL, without trailing slash.
	 * @param string                $baseDir          Uploads base directory, without trailing slash.
	 * @param Closure(string): ?int $attachmentLookup Returns the attachment id of a URL, or null.
	 */
	public function __construct( string $baseUrl, string $baseDir, Closure $attachmentLookup ) {
		$this->baseUrl          = rtrim( $baseUrl, '/' );
		$this->baseDir          = rtrim( $baseDir, '/' );
		$this->attachmentLookup = $attachmentLookup;
	}

	/**
	 * Resolves a video URL.
	 *
	 * @return LocalFile|string A LocalFile, or a translatable reason why the URL is not usable.
	 */
	public function resolveVideo( string $url ): LocalFile|string {
		return $this->resolve( $url, true );
	}

	/**
	 * Resolves a subtitle URL.
	 *
	 * @return LocalFile|string A LocalFile, or a translatable reason why the URL is not usable.
	 */
	public function resolveSubtitle( string $url ): LocalFile|string {
		return $this->resolve( $url, false );
	}

	/**
	 * Returns the path of a URL relative to the uploads directory, or null when it is outside.
	 */
	public function relativePath( string $url ): ?string {
		$base = $this->normalizeUrl( $this->baseUrl );
		$full = $this->normalizeUrl( $this->withoutQueryAndFragment( $url ) );

		if ( ! str_starts_with( $full, $base . '/' ) ) {
			return null;
		}

		$relative = rawurldecode( substr( $full, strlen( $base ) + 1 ) );

		if ( '' === $relative || str_contains( $relative, "\0" ) || str_contains( $relative, '\\' ) ) {
			return null;
		}

		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '..' === $segment ) {
				return null;
			}
		}

		return $relative;
	}

	/**
	 * @return LocalFile|string
	 */
	private function resolve( string $url, bool $isVideo ): LocalFile|string {
		$relative = $this->relativePath( $url );
		if ( null === $relative ) {
			return __( 'The file is not in the uploads directory of this site.', 'wp-scatter-elsewhere' );
		}

		// Containment is checked on the normalized path, not on realpath(): the uploads directory
		// may legitimately contain a mount point or a symbolic link.
		$path = $this->baseDir . '/' . $relative;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return __( 'The file does not exist or is not readable.', 'wp-scatter-elsewhere' );
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$mimeType  = $isVideo ? ( self::VIDEO_TYPES[ $extension ] ?? null ) : ( self::OTHER_TYPES[ $extension ] ?? 'application/octet-stream' );
		if ( null === $mimeType ) {
			return __( 'The file is not a supported video type.', 'wp-scatter-elsewhere' );
		}

		$cleanUrl     = $this->withoutQueryAndFragment( $url );
		$attachmentId = ( $this->attachmentLookup )( $cleanUrl );

		return new LocalFile( $path, $cleanUrl, $mimeType, (int) filesize( $path ), $attachmentId );
	}

	/**
	 * Drops the scheme and lower-cases the host, so http/https and host case do not matter.
	 */
	private function normalizeUrl( string $url ): string {
		$url   = (string) preg_replace( '#^(?:https?:)?//#i', '', $url );
		$slash = strpos( $url, '/' );

		if ( false === $slash ) {
			return strtolower( $url );
		}

		return strtolower( substr( $url, 0, $slash ) ) . substr( $url, $slash );
	}

	private function withoutQueryAndFragment( string $url ): string {
		return substr( $url, 0, strcspn( $url, '?#' ) );
	}
}
