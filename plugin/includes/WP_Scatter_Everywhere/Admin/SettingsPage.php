<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\Admin;

use InvalidArgumentException;
use WP_Scatter_Everywhere\Metadata\WordPressFactory as MetadataFactory;
use WP_Scatter_Everywhere\Settings\YouTubeSettings;
use WP_Scatter_Everywhere\YouTube\OAuthException;
use WP_Scatter_Everywhere\YouTube\WordPressFactory;

/**
 * The "Scatter Everywhere" settings page and the admin-post handlers of the YouTube connection.
 */
class SettingsPage {

	private const PAGE_SLUG = 'wp-scatter-everywhere';

	private const ACTION_SAVE       = 'wp_scatter_everywhere_save_credentials';
	private const ACTION_CONNECT    = 'wp_scatter_everywhere_youtube_connect';
	private const ACTION_DISCONNECT = 'wp_scatter_everywhere_youtube_disconnect';
	private const ACTION_TEMPLATES  = 'wp_scatter_everywhere_save_templates';

	private const NOTICE_ARG       = 'wp_scatter_everywhere_notice';
	private const ERROR_TRANSIENT  = 'wp_scatter_everywhere_error_';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'registerPage' ] );
		add_action( 'admin_post_' . self::ACTION_SAVE, [ $this, 'handleSave' ] );
		add_action( 'admin_post_' . self::ACTION_CONNECT, [ $this, 'handleConnect' ] );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, [ $this, 'handleDisconnect' ] );
		add_action( 'admin_post_' . self::ACTION_TEMPLATES, [ $this, 'handleSaveTemplates' ] );
		add_action( 'admin_post_' . WordPressFactory::CALLBACK_ACTION, [ $this, 'handleCallback' ] );
	}

	public function registerPage(): void {
		add_options_page(
			__( 'Scatter Everywhere', 'wp-scatter-everywhere' ),
			__( 'Scatter Everywhere', 'wp-scatter-everywhere' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	public function handleSave(): void {
		$this->guard( self::ACTION_SAVE );

		$clientId     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$clientSecret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';

		WordPressFactory::settings()->saveCredentials( $clientId, $clientSecret );

		$this->redirect( 'saved' );
	}

	public function handleSaveTemplates(): void {
		$this->guard( self::ACTION_TEMPLATES );

		$templates = [
			'title'       => isset( $_POST['title_template'] ) ? sanitize_text_field( wp_unslash( $_POST['title_template'] ) ) : '',
			'description' => isset( $_POST['description_template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description_template'] ) ) : '',
		];

		try {
			MetadataFactory::templateSettings()->save( $templates );
		} catch ( InvalidArgumentException $e ) {
			$this->redirectWithError( $e->getMessage() );
		}

		$this->redirect( 'templates_saved' );
	}

	public function handleConnect(): void {
		$this->guard( self::ACTION_CONNECT );

		$service = WordPressFactory::connectionService( WordPressFactory::settings() );

		try {
			$url = $service->startAuthorization( get_current_user_id() );
		} catch ( OAuthException $e ) {
			$this->redirectWithError( $e->getMessage() );
		}

		// The target is Google, which wp_safe_redirect() would refuse.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	public function handleCallback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-scatter-everywhere' ), '', [ 'response' => 403 ] );
		}

		// The single-use "state" parameter replaces a nonce here: the request comes from Google.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$query = [];
		foreach ( [ 'code', 'state', 'error' ] as $key ) {
			$query[ $key ] = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$service = WordPressFactory::connectionService( WordPressFactory::settings() );

		try {
			$service->completeAuthorization( get_current_user_id(), $query );
		} catch ( OAuthException $e ) {
			$this->redirectWithError( $e->getMessage() );
		}

		$this->redirect( 'connected' );
	}

	public function handleDisconnect(): void {
		$this->guard( self::ACTION_DISCONNECT );

		WordPressFactory::connectionService( WordPressFactory::settings() )->disconnect();

		$this->redirect( 'disconnected' );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-scatter-everywhere' ), '', [ 'response' => 403 ] );
		}

		$settings = WordPressFactory::settings();
		$status   = $settings->status();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Scatter Everywhere', 'wp-scatter-everywhere' ); ?></h1>
			<?php $this->renderNotice(); ?>

			<h2><?php echo esc_html__( 'YouTube', 'wp-scatter-everywhere' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpse-client-id"><?php echo esc_html__( 'Client ID', 'wp-scatter-everywhere' ); ?></label></th>
						<td><input type="text" id="wpse-client-id" name="client_id" class="regular-text code" autocomplete="off" value="<?php echo esc_attr( $settings->clientId() ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpse-client-secret"><?php echo esc_html__( 'Client secret', 'wp-scatter-everywhere' ); ?></label></th>
						<td>
							<input type="password" id="wpse-client-secret" name="client_secret" class="regular-text code" autocomplete="new-password" value="" placeholder="<?php echo esc_attr( '' !== $settings->clientSecret() ? '••••••••' : '' ); ?>" />
							<p class="description"><?php echo esc_html__( 'Leave empty to keep the saved secret. Changing the credentials disconnects the channel.', 'wp-scatter-everywhere' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Redirect URI', 'wp-scatter-everywhere' ); ?></th>
						<td>
							<code><?php echo esc_html( WordPressFactory::redirectUri() ); ?></code>
							<p class="description"><?php echo esc_html__( 'Add this address as an authorised redirect URI of the OAuth client in the Google Cloud console.', 'wp-scatter-everywhere' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save credentials', 'wp-scatter-everywhere' ) ); ?>
			</form>

			<h3><?php echo esc_html__( 'Connection', 'wp-scatter-everywhere' ); ?></h3>
			<?php $this->renderStatus( $settings, $status ); ?>

			<?php if ( $settings->hasCredentials() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CONNECT ); ?>" />
					<?php wp_nonce_field( self::ACTION_CONNECT ); ?>
					<?php submit_button( YouTubeSettings::STATUS_CONNECTED === $status ? __( 'Connect again', 'wp-scatter-everywhere' ) : __( 'Connect to YouTube', 'wp-scatter-everywhere' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<?php if ( YouTubeSettings::STATUS_CONNECTED === $status ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DISCONNECT ); ?>" />
					<?php wp_nonce_field( self::ACTION_DISCONNECT ); ?>
					<?php submit_button( __( 'Disconnect', 'wp-scatter-everywhere' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<?php $this->renderTemplates(); ?>
		</div>
		<?php
	}

	private function renderTemplates(): void {
		$templates = MetadataFactory::templateSettings();
		?>
		<h3><?php echo esc_html__( 'Video title and description', 'wp-scatter-everywhere' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TEMPLATES ); ?>" />
			<?php wp_nonce_field( self::ACTION_TEMPLATES ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpse-title-template"><?php echo esc_html__( 'Title template', 'wp-scatter-everywhere' ); ?></label></th>
					<td><input type="text" id="wpse-title-template" name="title_template" class="large-text code" value="<?php echo esc_attr( $templates->getTitleTemplate() ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpse-description-template"><?php echo esc_html__( 'Description template', 'wp-scatter-everywhere' ); ?></label></th>
					<td><textarea id="wpse-description-template" name="description_template" class="large-text code" rows="5"><?php echo esc_textarea( $templates->getDescriptionTemplate() ); ?></textarea></td>
				</tr>
			</table>
			<p class="description"><?php echo esc_html__( 'Available placeholders:', 'wp-scatter-everywhere' ); ?></p>
			<ul class="description" style="list-style:disc;margin-left:2em">
				<li><code>{title}</code> — <?php echo esc_html__( 'post title', 'wp-scatter-everywhere' ); ?></li>
				<li><code>{excerpt}</code> — <?php echo esc_html__( 'post excerpt', 'wp-scatter-everywhere' ); ?></li>
				<li><code>{permalink}</code> — <?php echo esc_html__( 'post address', 'wp-scatter-everywhere' ); ?></li>
				<li><code>{date}</code>, <code>{date:j F Y}</code> — <?php echo esc_html__( 'post date, with an optional PHP date format', 'wp-scatter-everywhere' ); ?></li>
				<li><code>{author}</code> — <?php echo esc_html__( 'author name', 'wp-scatter-everywhere' ); ?></li>
				<li><code>{categories}</code>, <code>{tags}</code>, <code>{terms:taxonomy}</code> — <?php echo esc_html__( 'terms of the post, separated by commas', 'wp-scatter-everywhere' ); ?></li>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: "{{", 2: "}}". */
							__( '%1$s and %2$s write literal braces', 'wp-scatter-everywhere' ),
							'{{',
							'}}'
						)
					);
					?>
				</li>
			</ul>
			<?php submit_button( __( 'Save templates', 'wp-scatter-everywhere' ) ); ?>
		</form>
		<?php
	}

	private function renderStatus( YouTubeSettings $settings, string $status ): void {
		if ( YouTubeSettings::STATUS_CONNECTED === $status ) {
			$channel = '' !== $settings->channelTitle() ? $settings->channelTitle() : __( '(unknown channel)', 'wp-scatter-everywhere' );
			$message = sprintf(
				/* translators: 1: YouTube channel title, 2: date of the connection. */
				__( 'Connected to the channel %1$s on %2$s.', 'wp-scatter-everywhere' ),
				$channel,
				wp_date( get_option( 'date_format' ), $settings->connectedAt() )
			);
			echo '<p>' . esc_html( $message ) . '</p>';
			return;
		}

		if ( YouTubeSettings::STATUS_NEEDS_REAUTH === $status ) {
			echo '<p>' . esc_html__( 'The authorisation has expired or was revoked: connect again.', 'wp-scatter-everywhere' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'If your Google Cloud project is in "Testing" status, Google may expire authorisations after a short period. Publish the project to avoid this.', 'wp-scatter-everywhere' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'Not connected.', 'wp-scatter-everywhere' ) . '</p>';
	}

	private function renderNotice(): void {
		// A display-only query argument selecting one of the fixed messages below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';

		$messages = [
			'saved'        => __( 'Credentials saved.', 'wp-scatter-everywhere' ),
			'connected'    => __( 'Connected to YouTube.', 'wp-scatter-everywhere' ),
			'disconnected' => __( 'Disconnected from YouTube.', 'wp-scatter-everywhere' ),
			'templates_saved' => __( 'Templates saved.', 'wp-scatter-everywhere' ),
		];

		if ( isset( $messages[ $code ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $code ] ) . '</p></div>';
			return;
		}

		if ( 'error' === $code ) {
			$key     = self::ERROR_TRANSIENT . get_current_user_id();
			$message = get_transient( $key );
			delete_transient( $key );

			echo '<div class="notice notice-error"><p>' . esc_html( is_string( $message ) && '' !== $message ? $message : __( 'The operation failed.', 'wp-scatter-everywhere' ) ) . '</p></div>';
		}
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-scatter-everywhere' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( $action );
	}

	private function redirect( string $notice ): never {
		wp_safe_redirect( add_query_arg( self::NOTICE_ARG, $notice, admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	private function redirectWithError( string $message ): never {
		set_transient( self::ERROR_TRANSIENT . get_current_user_id(), $message, 60 );

		$this->redirect( 'error' );
	}
}
