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
}
