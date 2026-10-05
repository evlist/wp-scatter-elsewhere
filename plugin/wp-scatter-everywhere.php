<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Plugin Name: WP Scatter Everywhere
 * Plugin URI:  https://github.com/evlist/wp-scatter-everywhere
 * Description: Scatter everywhere.
 * Version:     0.1.0
 * Author:      Eric van der Vlist
 * Author URI:  https://dyomedea.com
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wp-scatter-everywhere
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_SCATTER_EVERYWHERE_FILE', __FILE__ );
define( 'WP_SCATTER_EVERYWHERE_VERSION', '0.1.0' );

spl_autoload_register( function ( string $class ): void {
	$prefix = 'WP_Scatter_Everywhere\\';
	$base   = __DIR__ . '/includes/WP_Scatter_Everywhere/';

	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
	$file     = $base . $relative . '.php';

	if ( is_readable( $file ) ) {
		require $file;
	}
} );

add_action( 'plugins_loaded', function (): void {
	WP_Scatter_Everywhere\Admin\Bootstrap::init();
} );
