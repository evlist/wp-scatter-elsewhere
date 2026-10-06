<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Thumbnails;

use WP_Scatter_Elsewhere\YouTube\ThumbnailClient;
use WP_Scatter_Elsewhere\YouTube\ThumbnailException;

/**
 * Prepares an image and sets it as the thumbnail of a YouTube video.
 */
final class ThumbnailService {

	private ThumbnailClient $client;

	private ThumbnailPreparer $preparer;

	public function __construct( ThumbnailClient $client, ThumbnailPreparer $preparer ) {
		$this->client   = $client;
		$this->preparer = $preparer;
	}

	/**
	 * @throws ThumbnailException When the image cannot be prepared or YouTube refuses it.
	 */
	public function send( string $youtubeId, string $imagePath ): void {
		$data = $this->preparer->prepare( $imagePath );

		if ( null === $data ) {
			throw new ThumbnailException( __( 'The image cannot be prepared as a thumbnail (unreadable, unsupported, or too large even after compression).', 'wp-scatter-elsewhere' ) );
		}

		$this->client->set( $youtubeId, $data, 'image/jpeg' );
	}
}
