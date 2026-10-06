<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Metadata;

use Closure;
use WP_Scatter_Elsewhere\Rules\RuleMatcher;
use WP_Scatter_Elsewhere\Settings\TermRuleSettings;
use WP_Scatter_Elsewhere\Settings\UploadSettings;
use WP_Scatter_Elsewhere\YouTube\VideoMetadata;

/**
 * Builds the properties of a video from a post and the settings.
 */
final class VideoMetadataBuilder {

	private MetadataComposer $composer;

	private UploadSettings $settings;

	private TermRuleSettings $rules;

	private RuleMatcher $matcher;

	private KeywordNormalizer $keywords;

	/**
	 * @var Closure(): string
	 */
	private Closure $siteLocale;

	/**
	 * @param Closure(): string $siteLocale Returns the locale of the site, such as "fr_FR".
	 */
	public function __construct( MetadataComposer $composer, UploadSettings $settings, TermRuleSettings $rules, RuleMatcher $matcher, KeywordNormalizer $keywords, Closure $siteLocale ) {
		$this->composer   = $composer;
		$this->settings   = $settings;
		$this->rules      = $rules;
		$this->matcher    = $matcher;
		$this->keywords   = $keywords;
		$this->siteLocale = $siteLocale;
	}

	/**
	 * @param ?string $license Null for the default license of the settings.
	 */
	public function build( PostData $post, ?string $license = null ): VideoMetadata {
		$text = $this->composer->compose( $post );

		$language = '' !== $this->settings->language() ? $this->settings->language() : LanguageResolver::fromLocale( ( $this->siteLocale )() );

		$matched  = $this->matcher->match( $this->rules->rules(), $post );
		$keywords = $this->keywords->fit( $matched->keywords );

		return new VideoMetadata(
			$text['title'],
			$text['description'],
			$language,
			$license ?? $this->settings->defaultLicense(),
			$this->settings->sendsRecordingDate() ? $this->recordingDate( $post ) : null,
			VideoMetadata::DEFAULT_CATEGORY_ID,
			$this->settings->sendsThumbnail() ? $post->featuredImagePath : null,
			$keywords['kept'],
			$matched->playlists,
			$keywords['dropped']
		);
	}

	/**
	 * Noon UTC of the post date: the same calendar day whatever the time zone.
	 */
	private function recordingDate( PostData $post ): string {
		return $post->date->format( 'Y-m-d' ) . 'T12:00:00Z';
	}
}
