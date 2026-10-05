<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\Detection;

/**
 * Detects the videos of a post by reading its rendered public page.
 */
final class RenderedPageDetector implements VideoDetector {

	private PageFetcher $fetcher;

	private PageParser $parser;

	private LocalFileResolver $resolver;

	public function __construct( PageFetcher $fetcher, PageParser $parser, LocalFileResolver $resolver ) {
		$this->fetcher  = $fetcher;
		$this->parser   = $parser;
		$this->resolver = $resolver;
	}

	/**
	 * @return DetectedVideo[]
	 */
	public function detect( int $postId ): array {
		$page = $this->fetcher->fetch( $postId );

		/** @var array<string, array{file: ?LocalFile, reason: ?string, sources: string[], tracks: array<string, SubtitleTrack>, poster: ?string}> $merged */
		$merged = [];

		foreach ( $this->parser->parse( $page->html, $page->url ) as $parsed ) {
			[ $file, $reason ] = $this->resolveSources( $parsed->sources );
			$key               = null !== $file ? $file->path : $parsed->sources[0];

			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = [
					'file'    => $file,
					'reason'  => $reason,
					'sources' => [],
					'tracks'  => [],
					'poster'  => null,
				];
			}

			$merged[ $key ]['sources'] = array_values( array_unique( array_merge( $merged[ $key ]['sources'], $parsed->sources ) ) );
			$merged[ $key ]['poster']  = $merged[ $key ]['poster'] ?? $parsed->poster;

			foreach ( $parsed->tracks as $track ) {
				$subtitle = $this->resolveTrack( $track );
				$trackKey = ( $subtitle->file->path ?? $subtitle->url ) . '|' . (string) $subtitle->language;

				$merged[ $key ]['tracks'][ $trackKey ] ??= $subtitle;
			}
		}

		$videos = [];
		foreach ( $merged as $key => $data ) {
			$videos[] = new DetectedVideo(
				'v' . substr( sha1( $this->identity( $data['file'], $key ) ), 0, 12 ),
				$data['file'],
				$data['reason'],
				$data['sources'],
				array_values( $data['tracks'] ),
				$data['poster']
			);
		}

		return $videos;
	}

	/**
	 * Uses the first source that resolves to an eligible local file.
	 *
	 * @param string[] $sources
	 * @return array{0: ?LocalFile, 1: ?string}
	 */
	private function resolveSources( array $sources ): array {
		$reason = null;

		foreach ( $sources as $source ) {
			$result = $this->resolver->resolveVideo( $source );
			if ( $result instanceof LocalFile ) {
				return [ $result, null ];
			}
			$reason ??= $result;
		}

		return [ null, $reason ];
	}

	/**
	 * @param array{url: string, language: ?string, label: ?string} $track
	 */
	private function resolveTrack( array $track ): SubtitleTrack {
		$result = $this->resolver->resolveSubtitle( $track['url'] );

		if ( ! $result instanceof LocalFile ) {
			return new SubtitleTrack( $track['url'], $track['language'], $track['label'], null, $result );
		}

		$reason = null === $track['language'] ? __( 'The subtitle track has no language (srclang).', 'wp-scatter-everywhere' ) : null;

		return new SubtitleTrack( $track['url'], $track['language'], $track['label'], $result, $reason );
	}

	/**
	 * The identity is the path relative to the uploads directory when known, so it survives a change
	 * of the uploads location.
	 */
	private function identity( ?LocalFile $file, string $fallback ): string {
		if ( null === $file ) {
			return $fallback;
		}

		return $this->resolver->relativePath( $file->url ) ?? $file->path;
	}
}
