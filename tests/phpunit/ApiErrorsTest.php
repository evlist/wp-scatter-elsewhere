<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\YouTube\ApiErrors;

class ApiErrorsTest extends TestCase {

	public function test_describes_an_error_without_html(): void {
		$body = (string) json_encode( [ 'error' => [ 'message' => 'Check <code>snippet.name</code> please.', 'errors' => [ [ 'reason' => 'invalidMetadata' ] ] ] ] );

		$message = ApiErrors::describe( 400, $body );

		$this->assertSame( 'YouTube answered with HTTP status 400 (invalidMetadata). Check snippet.name please.', $message );
	}

	public function test_tolerates_a_body_that_is_not_json(): void {
		$this->assertSame( 'YouTube answered with HTTP status 502 (). ', ApiErrors::describe( 502, '<html>Bad gateway</html>' ) );
		$this->assertSame( '', ApiErrors::reason( '' ) );
	}

	public function test_recognises_quota_errors_only_with_status_403(): void {
		$quota = (string) json_encode( [ 'error' => [ 'errors' => [ [ 'reason' => 'quotaExceeded' ] ] ] ] );

		$this->assertTrue( ApiErrors::isQuota( 403, $quota ) );
		$this->assertFalse( ApiErrors::isQuota( 400, $quota ) );
		$this->assertFalse( ApiErrors::isQuota( 403, '{}' ) );
	}
}
