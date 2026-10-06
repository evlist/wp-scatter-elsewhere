<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Editor;

use WP_Scatter_Elsewhere\Settings\UploadSettings;

/**
 * Validates the request of the editor panel to upload a video.
 */
final class UploadRequestValidator {

	/**
	 * @param array<string, mixed> $params            The video_id, privacy and license sent by the panel.
	 * @param string[]             $uploadableVideoIds
	 * @return array{ok: bool, video_id: string, privacy: string, license: string, error: string}
	 */
	public function validate( array $params, array $uploadableVideoIds, string $defaultPrivacy, string $defaultLicense ): array {
		$videoId = trim( (string) ( $params['video_id'] ?? '' ) );
		$privacy = trim( (string) ( $params['privacy'] ?? '' ) );
		$license = trim( (string) ( $params['license'] ?? '' ) );

		$privacy = '' === $privacy ? $defaultPrivacy : $privacy;
		$license = '' === $license ? $defaultLicense : $license;

		$error = '';
		if ( '' === $videoId || ! in_array( $videoId, $uploadableVideoIds, true ) ) {
			$error = __( 'This video is not in the page of the post, or it cannot be uploaded.', 'wp-scatter-elsewhere' );
		} elseif ( ! UploadSettings::isValidPrivacy( $privacy ) ) {
			$error = __( 'The privacy must be private, unlisted or public.', 'wp-scatter-elsewhere' );
		} elseif ( ! UploadSettings::isValidLicense( $license ) ) {
			$error = __( 'The license must be youtube or creativeCommon.', 'wp-scatter-elsewhere' );
		}

		return [
			'ok'       => '' === $error,
			'video_id' => $videoId,
			'privacy'  => $privacy,
			'license'  => $license,
			'error'    => $error,
		];
	}
}
