<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Admin;

use WP_Scatter_Elsewhere\Matching\WordPressFactory as MatchingFactory;
use WP_Scatter_Elsewhere\Rest\YouTubeController;
use WP_Scatter_Elsewhere\YouTube\WordPressFactory;

/**
 * Tools > Scatter Elsewhere - Link videos: scan the old posts, review the proposed matches and link them.
 */
class LinkToolsPage {

	public const SLUG = 'wp-scatter-elsewhere-link';

	private const HANDLE = 'wp-scatter-elsewhere-link-tools';

	private string $hook = '';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'registerPage' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
	}

	public function registerPage(): void {
		$hook = add_management_page(
			__( 'Scatter Elsewhere - Link videos', 'wp-scatter-elsewhere' ),
			__( 'Scatter Elsewhere - Link videos', 'wp-scatter-elsewhere' ),
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
			plugins_url( 'assets/js/link-tools.js', WP_SCATTER_ELSEWHERE_FILE ),
			[ 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ],
			WP_SCATTER_ELSEWHERE_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'wp-scatter-elsewhere', plugin_dir_path( WP_SCATTER_ELSEWHERE_FILE ) . 'languages' );

		wp_add_inline_script(
			self::HANDLE,
			'window.wpScatterElsewhereLinkTools = ' . wp_json_encode(
				[
					'runs' => array_map(
						static function ( array $run ): array {
							$run['items'] = array_map(
								static function ( array $item ): array {
									$item['title'] = wp_strip_all_tags( get_the_title( $item['post'] ) );

									return $item;
								},
								$run['items']
							);

							return $run;
						},
						MatchingFactory::runLog()->runs()
					),
				]
			) . ';',
			'before'
		);
	}

	public function render(): void {
		if ( ! current_user_can( YouTubeController::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-scatter-elsewhere' ), '', [ 'response' => 403 ] );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Scatter Elsewhere - Link videos', 'wp-scatter-elsewhere' ); ?></h1>
			<?php if ( ! WordPressFactory::settings()->isConnected() ) : ?>
				<p>
					<?php echo esc_html__( 'The plugin is not connected to YouTube.', 'wp-scatter-elsewhere' ); ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=wp-scatter-elsewhere' ) ); ?>"><?php echo esc_html__( 'Open the settings', 'wp-scatter-elsewhere' ); ?></a>
				</p>
			<?php else : ?>
				<p class="description"><?php echo esc_html__( 'Finds the YouTube video of the posts published before the plugin and links it, after you have checked each match. Nothing is changed on YouTube.', 'wp-scatter-elsewhere' ); ?></p>
				<div id="wpse-link-tools"></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
