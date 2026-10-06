<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Subtitles;

use Closure;
use WP_Scatter_Elsewhere\Settings\UploadSettings;
use WP_Scatter_Elsewhere\YouTube\CaptionClient;
use WP_Scatter_Elsewhere\YouTube\CaptionException;

/**
 * Synchronises the subtitle files of a video with the caption tracks of its YouTube video.
 */
final class SubtitleService {

	private CaptionClient $client;

	private SubtitleConverter $converter;

	private UploadSettings $settings;

	/**
	 * @var Closure(string): (string|false)
	 */
	private Closure $reader;

	/**
	 * @param Closure(string): (string|false) $reader Returns the content of a file, or false when it cannot be read.
	 */
	public function __construct( CaptionClient $client, SubtitleConverter $converter, UploadSettings $settings, Closure $reader ) {
		$this->client    = $client;
		$this->converter = $converter;
		$this->settings  = $settings;
		$this->reader    = $reader;
	}

	/**
	 * Adds the tracks that YouTube does not have for a language, and replaces the ones it has.
	 *
	 * A problem with one track does not stop the others; a quota error stops everything.
	 *
	 * @param array<int, array{language: string, path: string}> $tracks
	 */
	public function sync( string $youtubeId, array $tracks ): SubtitleResult {
		$tracks = $this->onePerLanguage( $tracks );

		if ( [] === $tracks ) {
			return new SubtitleResult( [], [] );
		}

		$actions = [];
		$errors  = [];

		try {
			$existing = $this->client->standardTracks( $youtubeId );
		} catch ( CaptionException $e ) {
			return new SubtitleResult( [], [ '*' => $e->getMessage() ] );
		}

		foreach ( $tracks as $track ) {
			$language = $track['language'];

			$content = $this->content( $track['path'] );
			if ( null === $content ) {
				$errors[ $language ] = __( 'The subtitle file cannot be read, or it holds no cue.', 'wp-scatter-elsewhere' );
				continue;
			}

			try {
				if ( isset( $existing[ $language ] ) ) {
					$this->client->replace( $existing[ $language ], $content );
					$actions[ $language ] = SubtitleResult::REPLACED;
				} else {
					$this->client->insert( $youtubeId, $language, $content );
					$actions[ $language ] = SubtitleResult::INSERTED;
				}
			} catch ( CaptionException $e ) {
				$errors[ $language ] = $e->getMessage();

				if ( $e->isQuota() ) {
					break;
				}
			}
		}

		return new SubtitleResult( $actions, $errors );
	}

	/**
	 * The content to send, in the configured format; null when the file is unreadable or empty.
	 */
	private function content( string $path ): ?string {
		$raw = ( $this->reader )( $path );
		if ( false === $raw || '' === trim( $raw ) ) {
			return null;
		}

		$format = $this->settings->subtitleFormat();

		if ( UploadSettings::SUBTITLE_FORMAT_VTT === $format ) {
			return $raw;
		}

		$converted = UploadSettings::SUBTITLE_FORMAT_SRT === $format ? $this->converter->vttToSrt( $raw ) : $this->converter->vttToSbv( $raw );

		return '' === $converted ? null : $converted;
	}

	/**
	 * @param array<int, array{language: string, path: string}> $tracks
	 * @return array<int, array{language: string, path: string}>
	 */
	private function onePerLanguage( array $tracks ): array {
		$unique = [];
		foreach ( $tracks as $track ) {
			$unique[ $track['language'] ] ??= $track;
		}

		return array_values( $unique );
	}
}
