<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Admin;

use WP_Scatter_Elsewhere\Cli\Command;
use WP_Scatter_Elsewhere\YouTube\WordPressFactory;

class Bootstrap {

	public static function init(): void {
		new SettingsPage();

		add_action(
			WordPressFactory::UPLOAD_HOOK,
			static function ( string $jobId ): void {
				// WP-Cron runs are short: the uploader sends chunks for 20 seconds, then reschedules itself.
				WordPressFactory::uploadService()->process( $jobId, 20 );
			}
		);

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'scatter-elsewhere', Command::class );
		}
	}
}
