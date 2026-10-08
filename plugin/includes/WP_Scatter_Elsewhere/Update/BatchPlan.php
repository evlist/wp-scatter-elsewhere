<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Update;

use InvalidArgumentException;
use WP_Scatter_Elsewhere\Settings\UploadSettings;

/**
 * What a bulk update does: which videos, which fields and how the lists are handled. Validated once, here,
 * whether it comes from the command line or from the administration page.
 */
final class BatchPlan {

	/**
	 * @param string[] $fields    Fields to update, among BatchUpdater::FIELDS.
	 * @param int[]    $posts     Only these posts, empty for all.
	 * @param string   $term      "taxonomy:slug", empty for any.
	 */
	public function __construct(
		public readonly array $fields,
		public readonly string $keywords,
		public readonly string $playlists,
		public readonly ?string $privacy,
		public readonly array $posts,
		public readonly ?string $since,
		public readonly ?string $until,
		public readonly string $term,
		public readonly int $limit
	) {
	}

	/**
	 * @param array<string, mixed> $data fields (list or comma-separated string), keywords, playlists, privacy, posts (list or
	 *                                   comma-separated string), since, until, term, limit.
	 * @throws InvalidArgumentException With a readable message.
	 */
	public static function fromArray( array $data ): self {
		$fields = self::list( $data['fields'] ?? [] );

		if ( [] === $fields ) {
			throw new InvalidArgumentException( __( 'Say which fields to update.', 'wp-scatter-elsewhere' ) );
		}

		if ( in_array( 'all-safe', $fields, true ) ) {
			$fields = array_merge( array_diff( $fields, [ 'all-safe' ] ), array_diff( BatchUpdater::FIELDS, [ 'title', 'description', 'privacy' ] ) );
		}

		foreach ( $fields as $field ) {
			if ( ! in_array( $field, BatchUpdater::FIELDS, true ) ) {
				throw new InvalidArgumentException( sprintf( /* translators: %s: field name. */ __( 'Unknown field: %s', 'wp-scatter-elsewhere' ), $field ) );
			}
		}

		$keywords  = (string) ( $data['keywords'] ?? VideoDiff::MODE_ADD );
		$playlists = (string) ( $data['playlists'] ?? VideoDiff::MODE_ADD );
		foreach ( [ $keywords, $playlists ] as $mode ) {
			if ( ! in_array( $mode, [ VideoDiff::MODE_ADD, VideoDiff::MODE_SYNC ], true ) ) {
				throw new InvalidArgumentException( __( 'The mode must be add or sync.', 'wp-scatter-elsewhere' ) );
			}
		}

		$privacy = isset( $data['privacy'] ) && '' !== (string) $data['privacy'] ? (string) $data['privacy'] : null;
		if ( in_array( 'privacy', $fields, true ) && ( null === $privacy || ! UploadSettings::isValidPrivacy( $privacy ) ) ) {
			throw new InvalidArgumentException( __( 'The field privacy needs a privacy: private, unlisted or public.', 'wp-scatter-elsewhere' ) );
		}

		$term = trim( (string) ( $data['term'] ?? '' ) );
		if ( '' !== $term && 1 !== preg_match( '/^[A-Za-z0-9_-]+:.+$/', $term ) ) {
			throw new InvalidArgumentException( __( 'The term must be written taxonomy:slug.', 'wp-scatter-elsewhere' ) );
		}

		return new self(
			array_values( array_unique( $fields ) ),
			$keywords,
			$playlists,
			in_array( 'privacy', $fields, true ) ? $privacy : null,
			array_values( array_filter( array_map( 'intval', self::list( $data['posts'] ?? [] ) ), static fn( int $id ): bool => $id > 0 ) ),
			self::date( $data['since'] ?? null ),
			self::date( $data['until'] ?? null ),
			$term,
			max( 0, (int) ( $data['limit'] ?? 0 ) )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'fields'    => $this->fields,
			'keywords'  => $this->keywords,
			'playlists' => $this->playlists,
			'privacy'   => $this->privacy,
			'posts'     => $this->posts,
			'since'     => $this->since,
			'until'     => $this->until,
			'term'      => $this->term,
			'limit'     => $this->limit,
		];
	}

	/**
	 * @param mixed $value
	 * @return string[]
	 */
	private static function list( mixed $value ): array {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );

		return array_values( array_filter( array_map( static fn( $item ): string => trim( (string) $item ), $items ), static fn( string $item ): bool => '' !== $item ) );
	}

	/**
	 * @param mixed $value
	 * @throws InvalidArgumentException When the date is not YYYY-MM-DD.
	 */
	private static function date( mixed $value ): ?string {
		$value = trim( (string) ( $value ?? '' ) );
		if ( '' === $value ) {
			return null;
		}

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) || false === strtotime( $value ) ) {
			throw new InvalidArgumentException( __( 'A date must be written YYYY-MM-DD.', 'wp-scatter-elsewhere' ) );
		}

		return $value;
	}
}
