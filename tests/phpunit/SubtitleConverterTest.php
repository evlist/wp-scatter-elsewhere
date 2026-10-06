<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Subtitles\SubtitleConverter;

class SubtitleConverterTest extends TestCase {

	private function convert( string $vtt ): string {
		return ( new SubtitleConverter() )->vttToSrt( $vtt );
	}

	public function test_converts_a_typical_file(): void {
		$vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:04.500\nBonjour à tous.\n\n00:00:05.000 --> 00:00:08.250\nPremière ligne\nDeuxième ligne\n";

		$this->assertSame(
			"1\n00:00:01,000 --> 00:00:04,500\nBonjour à tous.\n\n2\n00:00:05,000 --> 00:00:08,250\nPremière ligne\nDeuxième ligne\n",
			$this->convert( $vtt )
		);
	}

	public function test_drops_header_metadata_notes_styles_and_regions(): void {
		$vtt = "\xEF\xBB\xBFWEBVTT - a title\nKind: captions\nLanguage: fr\n\nNOTE a comment\nover two lines\n\nSTYLE\n::cue { color: red }\n\nREGION\nid:r1\n\n00:01.000 --> 00:02.000\nTexte\n";

		$this->assertSame( "1\n00:00:01,000 --> 00:00:02,000\nTexte\n", $this->convert( $vtt ) );
	}

	public function test_drops_cue_identifiers_and_settings(): void {
		$vtt = "WEBVTT\n\nintro\n00:00:01.000 --> 00:00:02.000 align:start position:10% line:90%\nTexte\n\n2\n00:10:00.123 --> 01:00:00.5\nSuite\n";

		$this->assertSame( "1\n00:00:01,000 --> 00:00:02,000\nTexte\n\n2\n00:10:00,123 --> 01:00:00,500\nSuite\n", $this->convert( $vtt ) );
	}

	public function test_cleans_tags_and_entities_but_keeps_simple_formatting(): void {
		$vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n<v Eric>Salut <i>toi</i> &amp; <b>vous</b></v>\n<c.yellow>Jaune</c> <00:00:01.500>mot &lt;ok&gt;&nbsp;!\n<ruby>x</ruby><u>y</u>\n";

		$this->assertSame( "1\n00:00:01,000 --> 00:00:02,000\nSalut <i>toi</i> & <b>vous</b>\nJaune mot <ok> !\nx<u>y</u>\n", $this->convert( $vtt ) );
	}

	public function test_handles_windows_line_endings_and_extra_blank_lines(): void {
		$vtt = "WEBVTT\r\n\r\n\r\n00:00:01.000 --> 00:00:02.000\r\nUn\r\n\r\n\r\n\r\n00:00:03.000 --> 00:00:04.000\r\nDeux\r\n";

		$this->assertSame( "1\n00:00:01,000 --> 00:00:02,000\nUn\n\n2\n00:00:03,000 --> 00:00:04,000\nDeux\n", $this->convert( $vtt ) );
	}

	public function test_skips_cues_without_text_and_renumbers(): void {
		$vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n\n\n00:00:03.000 --> 00:00:04.000\n<c></c>\n\n00:00:05.000 --> 00:00:06.000\nReste\n";

		$this->assertSame( "1\n00:00:05,000 --> 00:00:06,000\nReste\n", $this->convert( $vtt ) );
	}

	public function test_a_file_without_cue_gives_an_empty_result(): void {
		$this->assertSame( '', $this->convert( '' ) );
		$this->assertSame( '', $this->convert( "WEBVTT\n" ) );
		$this->assertSame( '', $this->convert( "not subtitles\n\nat all" ) );
	}

	private function sbv( string $vtt ): string {
		return ( new SubtitleConverter() )->vttToSbv( $vtt );
	}

	public function test_sbv_has_the_youtube_layout(): void {
		$vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:04.500\nBonjour à tous.\n\n00:00:05.000 --> 00:00:08.250\nPremière ligne\nDeuxième ligne\n";

		$this->assertSame(
			"0:00:01.000,0:00:04.500\nBonjour à tous.\n\n0:00:05.000,0:00:08.250\nPremière ligne\nDeuxième ligne\n",
			$this->sbv( $vtt )
		);
	}

	public function test_sbv_times_have_an_unpadded_hour_and_three_millisecond_digits(): void {
		$vtt = "WEBVTT\n\n00:01.5 --> 12:34.56\nA\n\n01:02:03.004 --> 10:00:00.999\nB\n";

		$this->assertSame( "0:00:01.500,0:12:34.560\nA\n\n1:02:03.004,10:00:00.999\nB\n", $this->sbv( $vtt ) );
	}

	public function test_sbv_has_no_formatting_and_no_cue_numbers(): void {
		$vtt = "WEBVTT\n\n1\n00:00:01.000 --> 00:00:02.000 align:start\n<v Eric>Salut <i>toi</i> &amp; <b>vous</b></v>\n<c.yellow>Jaune</c> <00:00:01.500>mot\n";

		$this->assertSame( "0:00:01.000,0:00:02.000\nSalut toi & vous\nJaune mot\n", $this->sbv( $vtt ) );
	}

	public function test_sbv_drops_header_notes_styles_and_empty_cues(): void {
		$vtt = "\xEF\xBB\xBFWEBVTT\r\nKind: captions\r\n\r\nNOTE comment\r\n\r\nSTYLE\r\n::cue { color: red }\r\n\r\n00:00:01.000 --> 00:00:02.000\r\n\r\n\r\n00:00:03.000 --> 00:00:04.000\r\nReste\r\n";

		$this->assertSame( "0:00:03.000,0:00:04.000\nReste\n", $this->sbv( $vtt ) );
		$this->assertSame( '', $this->sbv( '' ) );
		$this->assertSame( '', $this->sbv( "WEBVTT\n" ) );
	}
}
