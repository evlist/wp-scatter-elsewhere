<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\Detection;

use Closure;
use RuntimeException;

/**
 * Retrieves the public page of a published post. It only ever requests the permalink of a post.
 */
final class PageFetcher {

	/**
	 * @var Closure(int): ?array{permalink: string, status: string, password_protected: bool, viewable: bool}
	 */
	private Closure $postLookup;

	/**
	 * @var Closure(string): array{status: int, body: string}
	 */
	private Closure $http;

	/**
	 * @param Closure(int): ?array{permalink: string, status: string, password_protected: bool, viewable: bool} $postLookup
	 * @param Closure(string): array{status: int, body: string}                                               $http       Anonymous GET request; throws RuntimeException on transport errors.
	 */
	public function __construct( Closure $postLookup, Closure $http ) {
		$this->postLookup = $postLookup;
		$this->http       = $http;
	}

	/**
	 * @throws PostNotEligibleException When the post is not public.
	 * @throws PageFetchException       When the page cannot be retrieved.
	 */
	public function fetch( int $postId ): FetchedPage {
		$post = ( $this->postLookup )( $postId );

		if ( null === $post ) {
			throw new PostNotEligibleException( __( 'The post does not exist.', 'wp-scatter-everywhere' ) );
		}

		if ( 'publish' !== $post['status'] || ! $post['viewable'] || $post['password_protected'] || '' === $post['permalink'] ) {
			throw new PostNotEligibleException( __( 'Only published posts without a password can be inspected.', 'wp-scatter-everywhere' ) );
		}

		try {
			$response = ( $this->http )( $post['permalink'] );
		} catch ( RuntimeException $e ) {
			throw new PageFetchException(
				sprintf(
					/* translators: %s: the error returned by the HTTP layer. */
					__( 'The public page of the post could not be requested: %s Check that the site can request its own pages (loopback requests).', 'wp-scatter-everywhere' ),
					$e->getMessage()
				),
				0,
				$e
			);
		}

		if ( 200 !== $response['status'] ) {
			throw new PageFetchException(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The public page of the post answered with HTTP status %d. If the site is protected by authentication, the page cannot be inspected.', 'wp-scatter-everywhere' ),
					$response['status']
				)
			);
		}

		if ( '' === trim( $response['body'] ) ) {
			throw new PageFetchException( __( 'The public page of the post is empty.', 'wp-scatter-everywhere' ) );
		}

		return new FetchedPage( $post['permalink'], $response['body'] );
	}
}
