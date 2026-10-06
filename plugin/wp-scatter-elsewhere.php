<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Plugin Name: WP Scatter Elsewhere
 * Plugin URI:  https://github.com/evlist/wp-scatter-elsewhere
 * Description: Disperses content and media published on a blog to other places, starting with YouTube.
 * Version:     0.1.0
 * Author:      Eric van der Vlist
 * Author URI:  https://dyomedea.com
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wp-scatter-elsewhere
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_SCATTER_ELSEWHERE_FILE', __FILE__ );
define( 'WP_SCATTER_ELSEWHERE_VERSION', '0.1.0' );

spl_autoload_register( function ( string $class ): void {
	$prefix = 'WP_Scatter_Elsewhere\\';
	$base   = __DIR__ . '/includes/WP_Scatter_Elsewhere/';

	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
	$file     = $base . $relative . '.php';

	if ( is_readable( $file ) ) {
		require $file;
	}
} );

require_once __DIR__ . '/includes/functions.php';

add_action( 'plugins_loaded', function (): void {
	WP_Scatter_Elsewhere\Admin\Bootstrap::init();
} );
