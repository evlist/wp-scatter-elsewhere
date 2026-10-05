<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Settings;

use Closure;
use InvalidArgumentException;
use WP_Scatter_Elsewhere\Metadata\TemplateParser;

/**
 * Title and description templates used to describe a video on YouTube.
 */
class MetadataTemplateSettings {

	private const OPTION_KEY = 'wp_scatter_elsewhere_youtube_templates';

	public const DEFAULT_TITLE       = '{title}';
	public const DEFAULT_DESCRIPTION = "{excerpt}\n\n{permalink}";

	/**
	 * @var Closure(): mixed
	 */
	private Closure $loader;

	/**
	 * @var Closure(array<string, string>): void
	 */
	private Closure $saver;

	private TemplateParser $parser;

	/**
	 * @param Closure(): mixed                     $loader
	 * @param Closure(array<string, string>): void $saver
	 */
	public function __construct( Closure $loader, Closure $saver, TemplateParser $parser ) {
		$this->loader = $loader;
		$this->saver  = $saver;
		$this->parser = $parser;
	}

	public static function optionKey(): string {
		return self::OPTION_KEY;
	}

	public function getTitleTemplate(): string {
		return $this->stored( 'title', self::DEFAULT_TITLE );
	}

	public function getDescriptionTemplate(): string {
		return $this->stored( 'description', self::DEFAULT_DESCRIPTION );
	}

	/**
	 * Validates templates and returns field-level error messages.
	 *
	 * @param array<string, mixed> $templates
	 * @return array<string, string>
	 */
	public function validate( array $templates ): array {
		$errors = [];

		foreach ( [ 'title', 'description' ] as $field ) {
			$error = $this->parser->validate( (string) ( $templates[ $field ] ?? '' ) );
			if ( null !== $error ) {
				$errors[ $field ] = $error;
			}
		}

		return $errors;
	}

	/**
	 * Saves the templates; the previous value is kept when validation fails.
	 *
	 * @param array<string, mixed> $templates
	 * @throws InvalidArgumentException When a template is invalid.
	 */
	public function save( array $templates ): void {
		$errors = $this->validate( $templates );
		if ( [] !== $errors ) {
			throw new InvalidArgumentException( implode( ' ', $errors ) );
		}

		( $this->saver )(
			[
				'title'       => $this->normalizeText( (string) ( $templates['title'] ?? '' ), false ),
				'description' => $this->normalizeText( (string) ( $templates['description'] ?? '' ), true ),
			]
		);
	}

	private function stored( string $field, string $default ): string {
		$value = ( $this->loader )();

		if ( ! is_array( $value ) || ! isset( $value[ $field ] ) || ! is_string( $value[ $field ] ) || '' === trim( $value[ $field ] ) ) {
			return $default;
		}

		return $value[ $field ];
	}

	private function normalizeText( string $text, bool $multiline ): string {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );

		if ( ! $multiline ) {
			$text = str_replace( "\n", ' ', $text );
		}

		return trim( $text );
	}
}
