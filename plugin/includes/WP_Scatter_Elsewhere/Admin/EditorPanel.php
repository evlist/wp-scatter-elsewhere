<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Admin;

use WP_Scatter_Elsewhere\Rest\YouTubeController;

/**
 * Loads the YouTube panel in the block editor, for the users who may use it.
 */
class EditorPanel {

	private const HANDLE = 'wp-scatter-elsewhere-editor-panel';

	public function __construct() {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueAssets' ] );
	}

	public function enqueueAssets(): void {
		if ( ! current_user_can( YouTubeController::capability() ) ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/editor-youtube-panel.js', WP_SCATTER_ELSEWHERE_FILE ),
			[ 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ],
			WP_SCATTER_ELSEWHERE_VERSION,
			true
		);

		wp_set_script_translations(
			self::HANDLE,
			'wp-scatter-elsewhere',
			plugin_dir_path( WP_SCATTER_ELSEWHERE_FILE ) . 'languages'
		);
	}
}
