<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Metadata;

use DateTimeImmutable;
use WP_Post;
use WP_Scatter_Elsewhere\Rules\RuleMatcher;
use WP_Scatter_Elsewhere\Rules\WordPressFactory as RulesFactory;
use WP_Scatter_Elsewhere\Settings\MetadataTemplateSettings;
use WP_Scatter_Elsewhere\YouTube\WordPressFactory as YouTubeFactory;

/**
 * Wires the metadata classes to WordPress. Contains no logic worth testing without WordPress.
 */
final class WordPressFactory {

	public static function templateSettings(): MetadataTemplateSettings {
		return new MetadataTemplateSettings(
			static fn(): mixed => get_option( MetadataTemplateSettings::optionKey(), false ),
			static function ( array $value ): void {
				update_option( MetadataTemplateSettings::optionKey(), $value, false );
			},
			new TemplateParser()
		);
	}

	public static function composer(): MetadataComposer {
		$parser = new TemplateParser();

		return new MetadataComposer(
			self::templateSettings(),
			new TemplateRenderer(
				$parser,
				static function ( DateTimeImmutable $date, ?string $format ): string {
					$format = null === $format ? (string) get_option( 'date_format' ) : $format;

					return (string) wp_date( $format, $date->getTimestamp() );
				}
			),
			new YouTubeTextNormalizer()
		);
	}

	/**
	 * Slugs of the ancestors of a term (none for a flat taxonomy such as the tags).
	 *
	 * @return string[]
	 */
	private static function ancestorSlugs( \WP_Term $term, string $taxonomy ): array {
		$slugs = [];
		foreach ( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) as $ancestorId ) {
			$ancestor = get_term( (int) $ancestorId, $taxonomy );
			if ( $ancestor instanceof \WP_Term ) {
				$slugs[] = $ancestor->slug;
			}
		}

		return $slugs;
	}

	/**
	 * Path of the original file of the featured image of a post, or null.
	 */
	private static function featuredImagePath( WP_Post $post ): ?string {
		$id = (int) get_post_thumbnail_id( $post );
		if ( $id <= 0 ) {
			return null;
		}

		$path = get_attached_file( $id );

		return is_string( $path ) && is_readable( $path ) ? $path : null;
	}

	public static function videoMetadataBuilder(): VideoMetadataBuilder {
		return new VideoMetadataBuilder(
			self::composer(),
			YouTubeFactory::uploadSettings(),
			RulesFactory::settings(),
			new RuleMatcher(),
			new KeywordNormalizer(),
			static fn(): string => (string) get_locale()
		);
	}

	/**
	 * Builds the template data of a post. The date is the post date, which is the event date for
	 * back-dated posts.
	 */
	public static function postData( WP_Post $post ): PostData {
		$date = get_post_datetime( $post );

		$terms   = [];
		$details = [];
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$list = get_the_terms( $post, $taxonomy );
			if ( is_array( $list ) ) {
				$terms[ $taxonomy ]   = array_map( static fn( $term ): string => $term->name, $list );
				$details[ $taxonomy ] = array_map(
					static fn( $term ): array => [
						'slug'      => $term->slug,
						'ancestors' => self::ancestorSlugs( $term, $taxonomy ),
					],
					$list
				);
			}
		}

		return new PostData(
			get_the_title( $post ),
			get_the_excerpt( $post ),
			(string) get_permalink( $post ),
			$date instanceof DateTimeImmutable ? $date : new DateTimeImmutable( '@' . (int) get_post_time( 'U', true, $post ) ),
			(string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			$terms,
			self::featuredImagePath( $post ),
			$details
		);
	}
}
