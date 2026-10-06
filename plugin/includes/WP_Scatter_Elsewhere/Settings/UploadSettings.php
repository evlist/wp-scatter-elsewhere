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
	public function save( string $privacy, string $license, string $language, bool $sendRecordingDate ): void {
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

		( $this->saver )(
			[
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
