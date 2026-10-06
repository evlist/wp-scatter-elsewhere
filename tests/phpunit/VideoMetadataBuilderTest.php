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
use WP_Scatter_Elsewhere\Settings\MetadataTemplateSettings;
use WP_Scatter_Elsewhere\Settings\UploadSettings;

class VideoMetadataBuilderTest extends TestCase {

	private function builder( mixed $uploadSettings = [], string $locale = 'fr_FR' ): VideoMetadataBuilder {
		$parser = new TemplateParser();

		return new VideoMetadataBuilder(
			new MetadataComposer(
				new MetadataTemplateSettings( static fn(): mixed => false, static function ( array $v ): void {}, $parser ),
				new TemplateRenderer( $parser, static fn( DateTimeImmutable $date, ?string $format ): string => $date->format( $format ?? 'Y-m-d' ) ),
				new YouTubeTextNormalizer()
			),
			new UploadSettings( static fn(): mixed => $uploadSettings, static function ( array $v ): void {} ),
			static fn(): string => $locale
		);
	}

	private function post( string $date = '2026-10-05 23:07:21', string $timezone = 'Europe/Paris' ): PostData {
		return new PostData( 'Grenoble ⇾ Salers', 'Une étape.', 'https://example.org/p/', new DateTimeImmutable( $date, new DateTimeZone( $timezone ) ), 'Eric' );
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
}
