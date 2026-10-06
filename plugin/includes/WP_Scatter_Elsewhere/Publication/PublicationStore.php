<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Publication;

use Closure;

/**
 * Persists the publications of a post, keyed by detected video id, in a post meta.
 */
class PublicationStore {

	public const META_KEY = '_wp_scatter_elsewhere_youtube';

	/**
	 * @var Closure(int): mixed
	 */
	private Closure $loader;

	/**
	 * @var Closure(int, array<string, array<string, mixed>>): void
	 */
	private Closure $saver;

	/**
	 * @param Closure(int): mixed                                       $loader Returns the stored value for a post.
	 * @param Closure(int, array<string, array<string, mixed>>): void $saver  Stores the value for a post.
	 */
	public function __construct( Closure $loader, Closure $saver ) {
		$this->loader = $loader;
		$this->saver  = $saver;
	}

	public function get( int $postId, string $videoId ): ?Publication {
		return $this->forPost( $postId )[ $videoId ] ?? null;
	}

	/**
	 * @return array<string, Publication> In recording order, keyed by detected video id.
	 */
	public function forPost( int $postId ): array {
		$stored = ( $this->loader )( $postId );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$publications = [];
		foreach ( $stored as $videoId => $data ) {
			if ( is_array( $data ) ) {
				$publication = Publication::fromArray( (string) $videoId, $data );
				if ( null !== $publication ) {
					$publications[ $publication->videoId ] = $publication;
				}
			}
		}

		return $publications;
	}

	public function save( int $postId, Publication $publication ): void {
		$all                         = $this->forPost( $postId );
		$all[ $publication->videoId ] = $publication;

		( $this->saver )( $postId, array_map( static fn( Publication $item ): array => $item->toArray(), $all ) );
	}
}
