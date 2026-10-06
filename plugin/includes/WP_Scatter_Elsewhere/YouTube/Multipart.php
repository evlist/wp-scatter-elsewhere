<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

/**
 * Builds the multipart/related body of a Google upload request: a JSON resource and a media file.
 */
final class Multipart {

	/**
	 * @param array<string, mixed> $resource
	 * @return array{contentType: string, body: string}
	 */
	public static function related( array $resource, string $media, string $mediaType, string $boundary ): array {
		$body = '--' . $boundary . "\r\n"
			. "Content-Type: application/json; charset=UTF-8\r\n\r\n"
			// This class does not depend on WordPress, hence json_encode() rather than wp_json_encode().
			. json_encode( $resource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\r\n" // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			. '--' . $boundary . "\r\n"
			. 'Content-Type: ' . $mediaType . "\r\n\r\n"
			. $media . "\r\n"
			. '--' . $boundary . "--\r\n";

		return [
			'contentType' => 'multipart/related; boundary=' . $boundary,
			'body'        => $body,
		];
	}
}
