<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\YouTube;

use Closure;
use RuntimeException;

/**
 * Reads the channel authorised by an access token, so the administrator can check the account.
 */
final class ChannelClient {

	private const ENDPOINT = 'https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true';

	/**
	 * @var Closure(string, string, array<string, string>, ?array<string, string>): array{status: int, body: array<string, mixed>}
	 */
	private Closure $http;

	/**
	 * @param Closure(string, string, array<string, string>, ?array<string, string>): array{status: int, body: array<string, mixed>} $http
	 */
	public function __construct( Closure $http ) {
		$this->http = $http;
	}

	/**
	 * @return array{id: string, title: string}|null Null when the channel cannot be read.
	 */
	public function fetch( string $accessToken ): ?array {
		try {
			$response = ( $this->http )( 'GET', self::ENDPOINT, [ 'Authorization' => 'Bearer ' . $accessToken ], null );
		} catch ( RuntimeException $e ) {
			return null;
		}

		$item = $response['body']['items'][0] ?? null;
		if ( 200 !== $response['status'] || ! is_array( $item ) || ! isset( $item['id'] ) ) {
			return null;
		}

		return [
			'id'    => (string) $item['id'],
			'title' => (string) ( $item['snippet']['title'] ?? '' ),
		];
	}
}
