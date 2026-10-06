<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Thumbnails;

use WP_Scatter_Elsewhere\YouTube\ThumbnailClient;
use WP_Scatter_Elsewhere\YouTube\WordPressFactory as YouTubeFactory;

/**
 * Wires the thumbnails to the image editor of WordPress. Contains no logic worth testing without WordPress.
 */
final class WordPressFactory {

	public static function service(): ThumbnailService {
		return new ThumbnailService(
			new ThumbnailClient( YouTubeFactory::uploadHttp(), YouTubeFactory::accessTokenProvider( YouTubeFactory::settings() ) ),
			new ThumbnailPreparer( [ self::class, 'render' ](...) )
		);
	}

	/**
	 * Crops an image to 16:9, scales it down to 1280 x 720 and returns it as JPEG data.
	 *
	 * @return string|false False when the image cannot be read or processed.
	 */
	public static function render( string $path, int $quality ): string|false {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return false;
		}

		$size = $editor->get_size();
		$box  = ThumbnailGeometry::cropBox( (int) $size['width'], (int) $size['height'] );

		if ( is_wp_error( $editor->crop( $box['x'], $box['y'], $box['width'], $box['height'] ) ) ) {
			return false;
		}

		$target = ThumbnailGeometry::targetSize( $box['width'], $box['height'] );
		if ( $target['width'] < $box['width'] && is_wp_error( $editor->resize( $target['width'], $target['height'], false ) ) ) {
			return false;
		}

		$editor->set_quality( $quality );

		$temporary = wp_tempnam( 'scatter-elsewhere-thumbnail' );
		$saved     = $editor->save( $temporary . '.jpg', 'image/jpeg' );

		$data = false;
		if ( ! is_wp_error( $saved ) && is_readable( $saved['path'] ) ) {
			$data = file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		if ( ! is_wp_error( $saved ) ) {
			wp_delete_file( $saved['path'] );
		}
		wp_delete_file( $temporary );

		return $data;
	}
}
