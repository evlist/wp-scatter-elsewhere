<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Admin;

use WP_Scatter_Elsewhere\Rest\YouTubeController;
use WP_Scatter_Elsewhere\YouTube\WordPressFactory;

/**
 * Tools > Scatter Elsewhere - Update videos: choose the videos and the fields, preview the differences and run
 * the update in the background.
 */
class UpdateToolsPage {

	public const SLUG = 'wp-scatter-elsewhere-update';

	private const HANDLE = 'wp-scatter-elsewhere-update-tools';

	private string $hook = '';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'registerPage' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
	}

	public function registerPage(): void {
		$hook = add_management_page(
			__( 'Scatter Elsewhere - Update videos', 'wp-scatter-elsewhere' ),
			__( 'Scatter Elsewhere - Update videos', 'wp-scatter-elsewhere' ),
			YouTubeController::capability(),
			self::SLUG,
			[ $this, 'render' ]
		);

		$this->hook = is_string( $hook ) ? $hook : '';
	}

	public function enqueueAssets( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_style( 'wp-components' );

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/update-tools.js', WP_SCATTER_ELSEWHERE_FILE ),
			[ 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ],
			WP_SCATTER_ELSEWHERE_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'wp-scatter-elsewhere', plugin_dir_path( WP_SCATTER_ELSEWHERE_FILE ) . 'languages' );

		wp_add_inline_script( self::HANDLE, 'window.wpScatterElsewhereUpdateTools = ' . wp_json_encode( [ 'terms' => $this->terms() ] ) . ';', 'before' );
	}

	/**
	 * The terms of the categories and tags, as "taxonomy:slug" and a readable name.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private function terms(): array {
		$terms = [];

		foreach ( [ 'category', 'post_tag' ] as $taxonomy ) {
			$found = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true, 'number' => 300 ] );
			if ( is_wp_error( $found ) ) {
				continue;
			}

			$object = get_taxonomy( $taxonomy );
			foreach ( $found as $term ) {
				$terms[] = [ 'value' => $taxonomy . ':' . $term->slug, 'label' => ( $object ? $object->labels->singular_name : $taxonomy ) . ': ' . $term->name ];
			}
		}

		return $terms;
	}

	public function render(): void {
		if ( ! current_user_can( YouTubeController::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-scatter-elsewhere' ), '', [ 'response' => 403 ] );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Scatter Elsewhere - Update videos', 'wp-scatter-elsewhere' ); ?></h1>
			<?php if ( ! WordPressFactory::settings()->isConnected() ) : ?>
				<p>
					<?php echo esc_html__( 'The plugin is not connected to YouTube.', 'wp-scatter-elsewhere' ); ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=wp-scatter-elsewhere' ) ); ?>"><?php echo esc_html__( 'Open the settings', 'wp-scatter-elsewhere' ); ?></a>
				</p>
			<?php else : ?>
				<p class="description"><?php echo esc_html__( 'Brings the YouTube videos linked to your posts in line with the current settings and rules, for the fields you choose. Only differences are sent. Nothing is sent before you start the update.', 'wp-scatter-elsewhere' ); ?></p>
				<div id="wpse-update-tools"></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
