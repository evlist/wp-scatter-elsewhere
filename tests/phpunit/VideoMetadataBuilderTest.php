<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Metadata\MetadataComposer;
use WP_Scatter_Elsewhere\Metadata\PostData;
use WP_Scatter_Elsewhere\Metadata\TemplateParser;
use WP_Scatter_Elsewhere\Metadata\TemplateRenderer;
use WP_Scatter_Elsewhere\Metadata\VideoMetadataBuilder;
use WP_Scatter_Elsewhere\Metadata\YouTubeTextNormalizer;
use WP_Scatter_Elsewhere\Metadata\KeywordNormalizer;
use WP_Scatter_Elsewhere\Rules\RuleMatcher;
use WP_Scatter_Elsewhere\Settings\MetadataTemplateSettings;
use WP_Scatter_Elsewhere\Settings\TermRuleSettings;
use WP_Scatter_Elsewhere\Settings\UploadSettings;

class VideoMetadataBuilderTest extends TestCase {

	private function builder( mixed $uploadSettings = [], string $locale = 'fr_FR', mixed $rules = [] ): VideoMetadataBuilder {
		$parser = new TemplateParser();

		return new VideoMetadataBuilder(
			new MetadataComposer(
				new MetadataTemplateSettings( static fn(): mixed => false, static function ( array $v ): void {}, $parser ),
				new TemplateRenderer( $parser, static fn( DateTimeImmutable $date, ?string $format ): string => $date->format( $format ?? 'Y-m-d' ) ),
				new YouTubeTextNormalizer()
			),
			new UploadSettings( static fn(): mixed => $uploadSettings, static function ( array $v ): void {} ),
			new TermRuleSettings( static fn(): mixed => $rules, static function ( array $v ): void {} ),
			new RuleMatcher(),
			new KeywordNormalizer(),
			static fn(): string => $locale
		);
	}

	private function post( string $date = '2026-10-05 23:07:21', string $timezone = 'Europe/Paris', ?string $image = '/uploads/featured.jpg', array $details = [] ): PostData {
		return new PostData( 'Grenoble ⇾ Salers', 'Une étape.', 'https://example.org/p/', new DateTimeImmutable( $date, new DateTimeZone( $timezone ) ), 'Eric', [], $image, $details );
	}

	public function test_builds_the_metadata_with_the_defaults(): void {
		$metadata = $this->builder()->build( $this->post() );

		$this->assertSame( 'Grenoble ⇾ Salers', $metadata->title );
		$this->assertSame( "Une étape.\n\nhttps://example.org/p/", $metadata->description );
		$this->assertSame( 'fr', $metadata->language );
		$this->assertSame( 'youtube', $metadata->license );
		$this->assertSame( '2026-10-05T12:00:00Z', $metadata->recordingDate );
		$this->assertSame( '22', $metadata->categoryId );
	}

	public function test_the_recording_date_is_the_calendar_day_of_the_post_whatever_the_time(): void {
		$this->assertSame( '2026-10-05T12:00:00Z', $this->builder()->build( $this->post( '2026-10-05 00:30:00' ) )->recordingDate );
		$this->assertSame( '2026-10-05T12:00:00Z', $this->builder()->build( $this->post( '2026-10-05 23:59:59', 'Pacific/Auckland' ) )->recordingDate );
	}

	public function test_the_recording_date_can_be_switched_off(): void {
		$this->assertNull( $this->builder( [ 'send_recording_date' => false ] )->build( $this->post() )->recordingDate );
	}

	public function test_a_language_set_by_hand_wins_over_the_site_language(): void {
		$this->assertSame( 'en', $this->builder( [ 'language' => 'en' ] )->build( $this->post() )->language );
		$this->assertSame( 'pt-BR', $this->builder( [], 'pt_BR' )->build( $this->post() )->language );
		$this->assertNull( $this->builder( [], '' )->build( $this->post() )->language );
	}

	public function test_the_license_comes_from_the_settings_or_the_argument(): void {
		$builder = $this->builder( [ 'default_license' => 'creativeCommon' ] );

		$this->assertSame( 'creativeCommon', $builder->build( $this->post() )->license );
		$this->assertSame( 'youtube', $builder->build( $this->post(), 'youtube' )->license );
	}

	public function test_the_featured_image_is_the_thumbnail_source_unless_switched_off_or_missing(): void {
		$this->assertSame( '/uploads/featured.jpg', $this->builder()->build( $this->post() )->thumbnailSource );
		$this->assertNull( $this->builder( [ 'send_thumbnail' => false ] )->build( $this->post() )->thumbnailSource );
		$this->assertNull( $this->builder()->build( $this->post( '2026-10-05 10:00:00', 'Europe/Paris', null ) )->thumbnailSource );
	}

	public function test_keywords_and_playlists_come_from_the_rules_of_the_terms_of_the_post(): void {
		$rules   = [
			[ 'taxonomy' => 'category', 'term' => 'vanlife', 'playlist_id' => 'PLvanlife12345', 'keyword' => 'vanlife', 'include_children' => false ],
			[ 'taxonomy' => 'post_tag', 'term' => 'salers', 'playlist_id' => '', 'keyword' => 'Salers', 'include_children' => false ],
			[ 'taxonomy' => 'category', 'term' => 'velo', 'playlist_id' => 'PLvelo1234567', 'keyword' => '', 'include_children' => false ],
		];
		$details = [
			'category' => [ [ 'slug' => 'vanlife', 'ancestors' => [] ] ],
			'post_tag' => [ [ 'slug' => 'salers', 'ancestors' => [] ] ],
		];

		$metadata = $this->builder( [], 'fr_FR', $rules )->build( $this->post( '2026-10-05 10:00:00', 'Europe/Paris', null, $details ) );

		$this->assertSame( [ 'vanlife', 'Salers' ], $metadata->keywords );
		$this->assertSame( [ 'PLvanlife12345' ], $metadata->playlists );
		$this->assertSame( [], $metadata->droppedKeywords );
	}

	public function test_keywords_that_do_not_fit_are_reported(): void {
		$rules   = [ [ 'taxonomy' => 'post_tag', 'term' => 'a', 'playlist_id' => '', 'keyword' => str_repeat( 'x', 300 ) ], [ 'taxonomy' => 'post_tag', 'term' => 'b', 'playlist_id' => '', 'keyword' => str_repeat( 'y', 300 ) ] ];
		$details = [ 'post_tag' => [ [ 'slug' => 'a', 'ancestors' => [] ], [ 'slug' => 'b', 'ancestors' => [] ] ] ];

		$metadata = $this->builder( [], 'fr_FR', $rules )->build( $this->post( '2026-10-05 10:00:00', 'Europe/Paris', null, $details ) );

		$this->assertSame( [ str_repeat( 'x', 300 ) ], $metadata->keywords );
		$this->assertSame( [ str_repeat( 'y', 300 ) ], $metadata->droppedKeywords );
	}

	public function test_without_rules_there_are_no_keywords_or_playlists(): void {
		$metadata = $this->builder()->build( $this->post() );

		$this->assertSame( [], $metadata->keywords );
		$this->assertSame( [], $metadata->playlists );
	}
}
