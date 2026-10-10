<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Outdooractive;

use RuntimeException;
use WP_Post;
use WP_Scatter_Elsewhere\Detection\LocalFile;
use WP_Scatter_Elsewhere\Detection\LocalFileResolver;
use WP_Scatter_Elsewhere\Detection\UrlResolver;
use WP_Scatter_Elsewhere\Metadata\TemplateParser;
use WP_Scatter_Elsewhere\Metadata\WordPressFactory as MetadataFactory;

/**
 * Wires the Outdooractive import package to WordPress. Contains no logic worth testing without WordPress.
 */
final class WordPressFactory {

	/** Meta of an attachment that gives the activity (folder) of a GPX file. */
	public const ACTIVITY_META = '_wp_scatter_elsewhere_oa_activity';

	public static function settings(): PackageSettings {
		return new PackageSettings(
			static fn(): mixed => get_option( PackageSettings::optionKey(), false ),
			static function ( array $value ): void {
				update_option( PackageSettings::optionKey(), $value, false );
			},
			new TemplateParser(),
			new ActivityResolver()
		);
	}

	private static function resolver(): LocalFileResolver {
		$uploads = wp_get_upload_dir();

		return new LocalFileResolver(
			$uploads['baseurl'],
			$uploads['basedir'],
			static function ( string $url ): ?int {
				$id = attachment_url_to_postid( $url );

				return $id > 0 ? $id : null;
			}
		);
	}

	/**
	 * The GPX files linked in a post, ready for the import: rewritten with the title and the description composed
	 * from the templates, and the folder (activity) they belong to.
	 *
	 * @return array{files: array<int, array{folder: string, name: string, content: string, title: string, description: string, source: string}>, problems: string[]}
	 */
	public static function files( WP_Post $post, ?string $activity = null ): array {
		$settings = self::settings();
		$data     = MetadataFactory::postData( $post );
		$renderer = MetadataFactory::renderer();
		$title    = trim( (string) preg_replace( '/\s+/u', ' ', $renderer->render( $settings->titleTemplate(), $data ) ) );
		$text     = trim( (string) preg_replace( "/\n{3,}/", "\n\n", $renderer->render( $settings->descriptionTemplate(), $data ) ) );
		$resolver = self::resolver();
		$baseUrl  = (string) get_permalink( $post );

		$files    = [];
		$problems = [];

		foreach ( ( new GpxLinks() )->find( $data->content() ) as $link ) {
			$url   = ( new UrlResolver() )->resolve( $baseUrl, $link );
			$local = $resolver->resolveFile( $url );

			if ( ! $local instanceof LocalFile ) {
				$problems[] = sprintf( /* translators: 1: address of the GPX file, 2: reason. */ __( '%1$s: %2$s', 'wp-scatter-elsewhere' ), $link, $local );
				continue;
			}

			$content = file_get_contents( $local->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			try {
				if ( false === $content ) {
					throw new \InvalidArgumentException( __( 'The file cannot be read.', 'wp-scatter-elsewhere' ) );
				}

				$enriched = ( new GpxEnricher() )->enrich( $content, $title, $text );
			} catch ( \InvalidArgumentException $e ) {
				$problems[] = $link . ': ' . $e->getMessage();
				continue;
			}

			$stored = null !== $local->attachmentId ? get_post_meta( $local->attachmentId, self::ACTIVITY_META, true ) : '';
			$name   = basename( $local->path );

			$files[] = [
				'folder'      => null !== $activity && '' !== $activity
					? ( new ActivityResolver() )->clean( $activity )
					: ( new ActivityResolver() )->resolve( is_string( $stored ) ? $stored : null, $name, $settings->suffixes(), $settings->defaultActivity() ),
				'name'        => $name,
				'content'     => $enriched,
				'title'       => $title,
				'description' => $text,
				'source'      => $link,
			];
		}

		return [ 'files' => $files, 'problems' => $problems ];
	}

	/**
	 * Writes a ZIP file with the entries given (path in the ZIP => content).
	 *
	 * @param array<string, string> $entries
	 * @throws RuntimeException When the ZIP cannot be written.
	 */
	public static function writeZip( string $path, array $entries ): void {
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new RuntimeException( __( 'The PHP zip extension is needed to write a ZIP file.', 'wp-scatter-elsewhere' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( sprintf( /* translators: %s: path of the file. */ __( 'The ZIP file %s cannot be written.', 'wp-scatter-elsewhere' ), $path ) );
		}

		foreach ( $entries as $name => $content ) {
			$zip->addFromString( $name, $content );
		}

		if ( ! $zip->close() ) {
			throw new RuntimeException( sprintf( /* translators: %s: path of the file. */ __( 'The ZIP file %s cannot be written.', 'wp-scatter-elsewhere' ), $path ) );
		}
	}
}
