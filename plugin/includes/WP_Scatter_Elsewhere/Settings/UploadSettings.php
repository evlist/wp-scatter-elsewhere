<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Settings;

use Closure;
use InvalidArgumentException;
use WP_Scatter_Elsewhere\Metadata\LanguageResolver;

/**
 * Settings of the YouTube uploads. The default privacy is "private" so nothing is published by accident.
 */
class UploadSettings {

	private const OPTION_KEY = 'wp_scatter_elsewhere_youtube_upload';

	public const PRIVACY_PRIVATE  = 'private';
	public const PRIVACY_UNLISTED = 'unlisted';
	public const PRIVACY_PUBLIC   = 'public';

	public const PRIVACY_VALUES = [ self::PRIVACY_PRIVATE, self::PRIVACY_UNLISTED, self::PRIVACY_PUBLIC ];

	public const LICENSE_YOUTUBE         = 'youtube';
	public const LICENSE_CREATIVE_COMMON = 'creativeCommon';

	public const LICENSE_VALUES = [ self::LICENSE_YOUTUBE, self::LICENSE_CREATIVE_COMMON ];

	public const DEFAULT_CATEGORY_ID = '22';

	public const SUBTITLE_FORMAT_SBV = 'sbv';
	public const SUBTITLE_FORMAT_SRT = 'srt';
	public const SUBTITLE_FORMAT_VTT = 'vtt';

	public const SUBTITLE_FORMAT_VALUES = [ self::SUBTITLE_FORMAT_SBV, self::SUBTITLE_FORMAT_SRT, self::SUBTITLE_FORMAT_VTT ];

	/**
	 * @var Closure(): mixed
	 */
	private Closure $loader;

	/**
	 * @var Closure(array<string, mixed>): void
	 */
	private Closure $saver;

	/**
	 * @param Closure(): mixed                   $loader
	 * @param Closure(array<string, mixed>): void $saver
	 */
	public function __construct( Closure $loader, Closure $saver ) {
		$this->loader = $loader;
		$this->saver  = $saver;
	}

	public static function optionKey(): string {
		return self::OPTION_KEY;
	}

	public static function isValidPrivacy( string $privacy ): bool {
		return in_array( $privacy, self::PRIVACY_VALUES, true );
	}

	public static function isValidLicense( string $license ): bool {
		return in_array( $license, self::LICENSE_VALUES, true );
	}

	public function defaultPrivacy(): string {
		$value = $this->stored( 'default_privacy' );

		return is_string( $value ) && self::isValidPrivacy( $value ) ? $value : self::PRIVACY_PRIVATE;
	}

	public function defaultLicense(): string {
		$value = $this->stored( 'default_license' );

		return is_string( $value ) && self::isValidLicense( $value ) ? $value : self::LICENSE_YOUTUBE;
	}

	/**
	 * The language entered by hand, or an empty string when it is derived from the site language.
	 */
	public function language(): string {
		$value = $this->stored( 'language' );

		return is_string( $value ) && LanguageResolver::isValid( $value ) ? $value : '';
	}

	public function subtitleFormat(): string {
		$value = $this->stored( 'subtitle_format' );

		return is_string( $value ) && in_array( $value, self::SUBTITLE_FORMAT_VALUES, true ) ? $value : self::SUBTITLE_FORMAT_SBV;
	}

	/**
	 * Whether the automatic captions of a language are removed when subtitles are sent for it.
	 */
	public function removesAutomaticCaptions(): bool {
		$value = $this->stored( 'remove_auto_captions' );

		return is_bool( $value ) ? $value : false;
	}

	public function sendsThumbnail(): bool {
		$value = $this->stored( 'send_thumbnail' );

		return is_bool( $value ) ? $value : true;
	}

	/**
	 * Numeric id of the YouTube category of the videos (22 is "People & Blogs").
	 */
	public function categoryId(): string {
		$value = $this->stored( 'category_id' );

		return is_string( $value ) && self::isValidCategory( $value ) ? $value : self::DEFAULT_CATEGORY_ID;
	}

	public function isEmbeddable(): bool {
		return $this->flag( 'embeddable', true );
	}

	public function publicStatsViewable(): bool {
		return $this->flag( 'public_stats_viewable', true );
	}

	/**
	 * Whether the videos are declared made for kids (YouTube then limits comments and personalised ads).
	 */
	public function madeForKids(): bool {
		return $this->flag( 'made_for_kids', false );
	}

	/**
	 * Whether the subscribers are notified of a public upload.
	 */
	public function notifiesSubscribers(): bool {
		return $this->flag( 'notify_subscribers', true );
	}

	public static function isValidCategory( string $category ): bool {
		return 1 === preg_match( '/^[0-9]{1,3}$/', $category );
	}

	private function flag( string $key, bool $default ): bool {
		$value = $this->stored( $key );

		return is_bool( $value ) ? $value : $default;
	}

	public function sendsRecordingDate(): bool {
		$value = $this->stored( 'send_recording_date' );

		return is_bool( $value ) ? $value : true;
	}

	/**
	 * Validates and saves the settings.
	 *
	 * @param string $language Empty to derive the language from the site language.
	 * @throws InvalidArgumentException When a value is invalid; nothing is saved.
	 */
	public function save( string $privacy, string $license, string $language, bool $sendRecordingDate, string $subtitleFormat = self::SUBTITLE_FORMAT_SBV, bool $removeAutoCaptions = false, bool $sendThumbnail = true, string $categoryId = self::DEFAULT_CATEGORY_ID, bool $embeddable = true, bool $publicStatsViewable = true, bool $madeForKids = false, bool $notifySubscribers = true ): void {
		$language = trim( $language );

		if ( ! self::isValidPrivacy( $privacy ) ) {
			throw new InvalidArgumentException( __( 'The privacy must be private, unlisted or public.', 'wp-scatter-elsewhere' ) );
		}

		if ( ! self::isValidLicense( $license ) ) {
			throw new InvalidArgumentException( __( 'The license must be youtube or creativeCommon.', 'wp-scatter-elsewhere' ) );
		}

		if ( '' !== $language && ! LanguageResolver::isValid( $language ) ) {
			throw new InvalidArgumentException( __( 'The language must be a language code such as fr, en or pt-BR.', 'wp-scatter-elsewhere' ) );
		}

		if ( ! in_array( $subtitleFormat, self::SUBTITLE_FORMAT_VALUES, true ) ) {
			throw new InvalidArgumentException( __( 'The subtitle format must be sbv, srt or vtt.', 'wp-scatter-elsewhere' ) );
		}

		$categoryId = trim( $categoryId );
		if ( ! self::isValidCategory( $categoryId ) ) {
			throw new InvalidArgumentException( __( 'The category must be the number of a YouTube category, such as 22.', 'wp-scatter-elsewhere' ) );
		}

		( $this->saver )(
			[
				'category_id'           => $categoryId,
				'embeddable'            => $embeddable,
				'public_stats_viewable' => $publicStatsViewable,
				'made_for_kids'         => $madeForKids,
				'notify_subscribers'    => $notifySubscribers,
				'send_thumbnail'      => $sendThumbnail,
				'subtitle_format'     => $subtitleFormat,
				'remove_auto_captions' => $removeAutoCaptions,
				'default_privacy'     => $privacy,
				'default_license'     => $license,
				'language'            => $language,
				'send_recording_date' => $sendRecordingDate,
			]
		);
	}

	private function stored( string $key ): mixed {
		$value = ( $this->loader )();

		return is_array( $value ) ? ( $value[ $key ] ?? null ) : null;
	}
}
