<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Settings;

use Closure;
use InvalidArgumentException;

/**
 * Settings of the YouTube uploads. The default privacy is "private" so nothing is published by accident.
 */
class UploadSettings {

	private const OPTION_KEY = 'wp_scatter_elsewhere_youtube_upload';

	public const PRIVACY_PRIVATE  = 'private';
	public const PRIVACY_UNLISTED = 'unlisted';
	public const PRIVACY_PUBLIC   = 'public';

	public const PRIVACY_VALUES = [ self::PRIVACY_PRIVATE, self::PRIVACY_UNLISTED, self::PRIVACY_PUBLIC ];

	/**
	 * @var Closure(): mixed
	 */
	private Closure $loader;

	/**
	 * @var Closure(array<string, string>): void
	 */
	private Closure $saver;

	/**
	 * @param Closure(): mixed                     $loader
	 * @param Closure(array<string, string>): void $saver
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

	public function defaultPrivacy(): string {
		$value = ( $this->loader )();

		if ( is_array( $value ) && isset( $value['default_privacy'] ) && is_string( $value['default_privacy'] ) && self::isValidPrivacy( $value['default_privacy'] ) ) {
			return $value['default_privacy'];
		}

		return self::PRIVACY_PRIVATE;
	}

	/**
	 * @throws InvalidArgumentException When the privacy is not one of the allowed values.
	 */
	public function saveDefaultPrivacy( string $privacy ): void {
		if ( ! self::isValidPrivacy( $privacy ) ) {
			throw new InvalidArgumentException( __( 'The privacy must be private, unlisted or public.', 'wp-scatter-elsewhere' ) );
		}

		( $this->saver )( [ 'default_privacy' => $privacy ] );
	}
}
