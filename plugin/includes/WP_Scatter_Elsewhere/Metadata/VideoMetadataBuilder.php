<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Metadata;

use Closure;
use WP_Scatter_Elsewhere\Settings\UploadSettings;
use WP_Scatter_Elsewhere\YouTube\VideoMetadata;

/**
 * Builds the properties of a video from a post and the settings.
 */
final class VideoMetadataBuilder {

	private MetadataComposer $composer;

	private UploadSettings $settings;

	/**
	 * @var Closure(): string
	 */
	private Closure $siteLocale;

	/**
	 * @param Closure(): string $siteLocale Returns the locale of the site, such as "fr_FR".
	 */
	public function __construct( MetadataComposer $composer, UploadSettings $settings, Closure $siteLocale ) {
		$this->composer   = $composer;
		$this->settings   = $settings;
		$this->siteLocale = $siteLocale;
	}

	/**
	 * @param ?string $license Null for the default license of the settings.
	 */
	public function build( PostData $post, ?string $license = null ): VideoMetadata {
		$text = $this->composer->compose( $post );

		$language = '' !== $this->settings->language() ? $this->settings->language() : LanguageResolver::fromLocale( ( $this->siteLocale )() );

		return new VideoMetadata(
			$text['title'],
			$text['description'],
			$language,
			$license ?? $this->settings->defaultLicense(),
			$this->settings->sendsRecordingDate() ? $this->recordingDate( $post ) : null
		);
	}

	/**
	 * Noon UTC of the post date: the same calendar day whatever the time zone.
	 */
	private function recordingDate( PostData $post ): string {
		return $post->date->format( 'Y-m-d' ) . 'T12:00:00Z';
	}
}
