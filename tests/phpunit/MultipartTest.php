<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\YouTube\Multipart;

class MultipartTest extends TestCase {

	public function test_builds_a_multipart_related_body(): void {
		$multipart = Multipart::related( [ 'snippet' => [ 'language' => 'fr', 'name' => 'Français' ] ], "1\n00:00:01,000 --> 00:00:02,000\nTexte\n", 'application/octet-stream', 'BOUND' );

		$this->assertSame( 'multipart/related; boundary=BOUND', $multipart['contentType'] );
		$this->assertSame(
			"--BOUND\r\n"
			. "Content-Type: application/json; charset=UTF-8\r\n\r\n"
			. "{\"snippet\":{\"language\":\"fr\",\"name\":\"Français\"}}\r\n"
			. "--BOUND\r\n"
			. "Content-Type: application/octet-stream\r\n\r\n"
			. "1\n00:00:01,000 --> 00:00:02,000\nTexte\n\r\n"
			. "--BOUND--\r\n",
			$multipart['body']
		);
	}
}
