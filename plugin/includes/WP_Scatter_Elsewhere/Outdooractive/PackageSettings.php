<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Outdooractive;

use Closure;
use InvalidArgumentException;
use WP_Scatter_Elsewhere\Metadata\TemplateParser;

/**
 * Settings of the Outdooractive import packages: the templates of the title and of the description of the trace,
 * the default activity (the folder of the ZIP) and the activities that go with the end of the name of a file.
 */
class PackageSettings {

	private const OPTION_KEY = 'wp_scatter_elsewhere_outdooractive';

	public const DEFAULT_TITLE       = '{title}';
	public const DEFAULT_DESCRIPTION = "{excerpt}\n\n{permalink}";
	public const DEFAULT_ACTIVITY    = 'Hiking';

	/** @var Closure(): mixed */
	private Closure $loader;

	/** @var Closure(array<string, mixed>): void */
	private Closure $saver;

	private TemplateParser $parser;

	private ActivityResolver $activities;

	/**
	 * @param Closure(): mixed                    $loader
	 * @param Closure(array<string, mixed>): void $saver
	 */
	public function __construct( Closure $loader, Closure $saver, TemplateParser $parser, ActivityResolver $activities ) {
		$this->loader     = $loader;
		$this->saver      = $saver;
		$this->parser     = $parser;
		$this->activities = $activities;
	}

	public static function optionKey(): string {
		return self::OPTION_KEY;
	}

	public function titleTemplate(): string {
		return $this->text( 'title', self::DEFAULT_TITLE );
	}

	public function descriptionTemplate(): string {
		return $this->text( 'description', self::DEFAULT_DESCRIPTION );
	}

	public function defaultActivity(): string {
		$value = $this->activities->clean( $this->text( 'default_activity', self::DEFAULT_ACTIVITY ) );

		return '' === $value ? self::DEFAULT_ACTIVITY : $value;
	}

	/**
	 * @return array<string, string> Activity (folder) by end of file name, in lower case.
	 */
	public function suffixes(): array {
		$stored = ( $this->loader )();

		return is_array( $stored ) && is_array( $stored['suffixes'] ?? null ) ? array_map( 'strval', $stored['suffixes'] ) : [];
	}

	/**
	 * The suffixes as the lines of a text area: "vanlife = Camping-car".
	 */
	public function suffixesText(): string {
		$lines = [];
		foreach ( $this->suffixes() as $suffix => $folder ) {
			$lines[] = $suffix . ' = ' . $folder;
		}

		return implode( "\n", $lines );
	}

	/**
	 * @return array<string, string> Folder by suffix; lines without "=" or with an empty side are ignored.
	 */
	public function parseSuffixes( string $text ): array {
		$suffixes = [];

		foreach ( preg_split( '/\R/u', $text ) ?: [] as $line ) {
			if ( ! str_contains( $line, '=' ) ) {
				continue;
			}

			[ $suffix, $folder ] = array_map( 'trim', explode( '=', $line, 2 ) );
			$suffix = strtolower( ltrim( $suffix, '-_' ) );
			$folder = $this->activities->clean( $folder );

			if ( '' !== $suffix && '' !== $folder ) {
				$suffixes[ $suffix ] = $folder;
			}
		}

		return $suffixes;
	}

	/**
	 * @throws InvalidArgumentException When a template is invalid; nothing is saved.
	 */
	public function save( string $title, string $description, string $defaultActivity, string $suffixes ): void {
		foreach ( [ 'title' => $title, 'description' => $description ] as $field => $template ) {
			$error = $this->parser->validate( $template );
			if ( null !== $error ) {
				throw new InvalidArgumentException( $error );
			}
		}

		( $this->saver )(
			[
				'title'            => trim( str_replace( [ "\r", "\n" ], ' ', $title ) ),
				'description'      => trim( str_replace( [ "\r\n", "\r" ], "\n", $description ) ),
				'default_activity' => $this->activities->clean( $defaultActivity ),
				'suffixes'         => $this->parseSuffixes( $suffixes ),
			]
		);
	}

	private function text( string $key, string $default ): string {
		$stored = ( $this->loader )();
		$value  = is_array( $stored ) ? ( $stored[ $key ] ?? null ) : null;

		return is_string( $value ) && '' !== trim( $value ) ? $value : $default;
	}
}
