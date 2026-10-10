<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Outdooractive;

use Closure;

/**
 * Lays out the files of an Outdooractive import ZIP: one folder per activity, the GPX files in them.
 */
final class PackageBuilder {

	/**
	 * @var Closure(string, array<string, string>): void
	 */
	private Closure $writer;

	/**
	 * @param Closure(string, array<string, string>): void $writer Writes a ZIP file with the given entries (path in the ZIP => content).
	 */
	public function __construct( Closure $writer ) {
		$this->writer = $writer;
	}

	/**
	 * @param array<int, array{folder: string, name: string, content: string}> $files
	 * @return string[] The paths in the ZIP, in order.
	 */
	public function build( string $zipPath, array $files ): array {
		$entries = [];

		foreach ( $files as $file ) {
			$folder = '' === $file['folder'] ? 'Hiking' : $file['folder'];
			$path   = $this->unique( $entries, $folder . '/' . $this->safeName( $file['name'] ) );

			$entries[ $path ] = $file['content'];
		}

		( $this->writer )( $zipPath, $entries );

		return array_keys( $entries );
	}

	private function safeName( string $name ): string {
		$name = trim( (string) preg_replace( '/[\x00-\x1f\/\\\\:*?"<>|]+/u', '-', $name ), ' .-' );

		return '' === $name ? 'track.gpx' : $name;
	}

	/**
	 * @param array<string, string> $entries
	 */
	private function unique( array $entries, string $path ): string {
		if ( ! isset( $entries[ $path ] ) ) {
			return $path;
		}

		$extension = pathinfo( $path, PATHINFO_EXTENSION );
		$stem      = '' === $extension ? $path : substr( $path, 0, -( strlen( $extension ) + 1 ) );

		for ( $number = 2; isset( $entries[ $stem . '-' . $number . ( '' === $extension ? '' : '.' . $extension ) ] ); ++$number ) {
			continue;
		}

		return $stem . '-' . $number . ( '' === $extension ? '' : '.' . $extension );
	}
}
