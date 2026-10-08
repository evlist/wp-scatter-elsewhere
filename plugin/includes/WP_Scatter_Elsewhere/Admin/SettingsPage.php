<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Admin;

use InvalidArgumentException;
use WP_Scatter_Elsewhere\Metadata\WordPressFactory as MetadataFactory;
use WP_Scatter_Elsewhere\Rules\WordPressFactory as RulesFactory;
use WP_Scatter_Elsewhere\Settings\UploadSettings;
use WP_Scatter_Elsewhere\Settings\YouTubeSettings;
use WP_Scatter_Elsewhere\YouTube\OAuthException;
use WP_Scatter_Elsewhere\YouTube\WordPressFactory;

/**
 * The "Scatter Elsewhere" settings page and the admin-post handlers of the YouTube connection.
 */
class SettingsPage {

	private const PAGE_SLUG = 'wp-scatter-elsewhere';

	private const ACTION_SAVE       = 'wp_scatter_elsewhere_save_credentials';
	private const ACTION_CONNECT    = 'wp_scatter_elsewhere_youtube_connect';
	private const ACTION_DISCONNECT = 'wp_scatter_elsewhere_youtube_disconnect';
	private const ACTION_TEMPLATES  = 'wp_scatter_elsewhere_save_templates';
	private const ACTION_UPLOAD     = 'wp_scatter_elsewhere_save_upload_settings';
	private const ACTION_RULES      = 'wp_scatter_elsewhere_save_term_rules';

	/** Empty rows offered below the existing rules. */
	private const BLANK_RULE_ROWS = 3;

	private const NOTICE_ARG       = 'wp_scatter_elsewhere_notice';
	private const ERROR_TRANSIENT  = 'wp_scatter_elsewhere_error_';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'registerPage' ] );
		add_action( 'admin_post_' . self::ACTION_SAVE, [ $this, 'handleSave' ] );
		add_action( 'admin_post_' . self::ACTION_CONNECT, [ $this, 'handleConnect' ] );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, [ $this, 'handleDisconnect' ] );
		add_action( 'admin_post_' . self::ACTION_TEMPLATES, [ $this, 'handleSaveTemplates' ] );
		add_action( 'admin_post_' . self::ACTION_UPLOAD, [ $this, 'handleSaveUploadSettings' ] );
		add_action( 'admin_post_' . self::ACTION_RULES, [ $this, 'handleSaveRules' ] );
		add_action( 'admin_post_' . WordPressFactory::CALLBACK_ACTION, [ $this, 'handleCallback' ] );
	}

	public function registerPage(): void {
		add_options_page(
			__( 'Scatter Elsewhere', 'wp-scatter-elsewhere' ),
			__( 'Scatter Elsewhere', 'wp-scatter-elsewhere' ),
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

	public function handleSaveUploadSettings(): void {
		$this->guard( self::ACTION_UPLOAD );

		$privacy  = isset( $_POST['default_privacy'] ) ? sanitize_key( wp_unslash( $_POST['default_privacy'] ) ) : '';
		$license  = isset( $_POST['default_license'] ) ? sanitize_text_field( wp_unslash( $_POST['default_license'] ) ) : '';
		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$subtitleFormat = isset( $_POST['subtitle_format'] ) ? sanitize_key( wp_unslash( $_POST['subtitle_format'] ) ) : '';

		try {
			WordPressFactory::uploadSettings()->save( $privacy, $license, $language, isset( $_POST['send_recording_date'] ), $subtitleFormat, isset( $_POST['remove_auto_captions'] ), isset( $_POST['send_thumbnail'] ) );
		} catch ( InvalidArgumentException $e ) {
			$this->redirectWithError( $e->getMessage() );
		}

		$this->redirect( 'upload_saved' );
	}

	public function handleSaveRules(): void {
		$this->guard( self::ACTION_RULES );

		// Each value is sanitized below, field by field.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$submitted = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : [];

		$rows = [];
		foreach ( $submitted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// The term is posted as "taxonomy:slug".
			[ $taxonomy, $term ] = array_pad( explode( ':', sanitize_text_field( (string) ( $row['term'] ?? '' ) ), 2 ), 2, '' );

			$rows[] = [
				'taxonomy'         => $taxonomy,
				'term'             => $term,
				'playlist_id'      => sanitize_text_field( (string) ( $row['playlist_id'] ?? '' ) ),
				'keyword'          => sanitize_text_field( (string) ( $row['keyword'] ?? '' ) ),
				'include_children' => ! empty( $row['include_children'] ),
				'remove'           => ! empty( $row['remove'] ),
			];
		}

		try {
			RulesFactory::settings()->save( $rows, RulesFactory::validator() );
		} catch ( InvalidArgumentException $e ) {
			$this->redirectWithError( $e->getMessage() );
		}

		RulesFactory::forgetPlaylistChoices();

		$this->redirect( 'rules_saved' );
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
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-scatter-elsewhere' ), '', [ 'response' => 403 ] );
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
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-scatter-elsewhere' ), '', [ 'response' => 403 ] );
		}

		$settings = WordPressFactory::settings();
		$status   = $settings->status();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Scatter Elsewhere', 'wp-scatter-elsewhere' ); ?></h1>
			<?php $this->renderNotice(); ?>

			<h2><?php echo esc_html__( 'YouTube', 'wp-scatter-elsewhere' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpse-client-id"><?php echo esc_html__( 'Client ID', 'wp-scatter-elsewhere' ); ?></label></th>
						<td><input type="text" id="wpse-client-id" name="client_id" class="regular-text code" autocomplete="off" value="<?php echo esc_attr( $settings->clientId() ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpse-client-secret"><?php echo esc_html__( 'Client secret', 'wp-scatter-elsewhere' ); ?></label></th>
						<td>
							<input type="password" id="wpse-client-secret" name="client_secret" class="regular-text code" autocomplete="new-password" value="" placeholder="<?php echo esc_attr( '' !== $settings->clientSecret() ? '••••••••' : '' ); ?>" />
							<p class="description"><?php echo esc_html__( 'Leave empty to keep the saved secret. Changing the credentials disconnects the channel.', 'wp-scatter-elsewhere' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Redirect URI', 'wp-scatter-elsewhere' ); ?></th>
						<td>
							<code><?php echo esc_html( WordPressFactory::redirectUri() ); ?></code>
							<p class="description"><?php echo esc_html__( 'Add this address as an authorised redirect URI of the OAuth client in the Google Cloud console.', 'wp-scatter-elsewhere' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save credentials', 'wp-scatter-elsewhere' ) ); ?>
			</form>

			<h3><?php echo esc_html__( 'Connection', 'wp-scatter-elsewhere' ); ?></h3>
			<?php $this->renderStatus( $settings, $status ); ?>

			<?php if ( $settings->hasCredentials() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CONNECT ); ?>" />
					<?php wp_nonce_field( self::ACTION_CONNECT ); ?>
					<?php submit_button( YouTubeSettings::STATUS_CONNECTED === $status ? __( 'Connect again', 'wp-scatter-elsewhere' ) : __( 'Connect to YouTube', 'wp-scatter-elsewhere' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<?php if ( YouTubeSettings::STATUS_CONNECTED === $status ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DISCONNECT ); ?>" />
					<?php wp_nonce_field( self::ACTION_DISCONNECT ); ?>
					<?php submit_button( __( 'Disconnect', 'wp-scatter-elsewhere' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<?php $this->renderTemplates(); ?>

			<?php $this->renderUploadSettings(); ?>

			<?php $this->renderRules(); ?>
		</div>
		<?php
	}

	private function renderTemplates(): void {
		$templates = MetadataFactory::templateSettings();
		?>
		<h3><?php echo esc_html__( 'Video title and description', 'wp-scatter-elsewhere' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TEMPLATES ); ?>" />
			<?php wp_nonce_field( self::ACTION_TEMPLATES ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpse-title-template"><?php echo esc_html__( 'Title template', 'wp-scatter-elsewhere' ); ?></label></th>
					<td><input type="text" id="wpse-title-template" name="title_template" class="large-text code" value="<?php echo esc_attr( $templates->getTitleTemplate() ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpse-description-template"><?php echo esc_html__( 'Description template', 'wp-scatter-elsewhere' ); ?></label></th>
					<td><textarea id="wpse-description-template" name="description_template" class="large-text code" rows="5"><?php echo esc_textarea( $templates->getDescriptionTemplate() ); ?></textarea></td>
				</tr>
			</table>
			<p class="description"><?php echo esc_html__( 'Available placeholders:', 'wp-scatter-elsewhere' ); ?></p>
			<ul class="description" style="list-style:disc;margin-left:2em">
				<li><code>{title}</code> — <?php echo esc_html__( 'post title', 'wp-scatter-elsewhere' ); ?></li>
				<li><code>{excerpt}</code> — <?php echo esc_html__( 'post excerpt', 'wp-scatter-elsewhere' ); ?></li>
				<li><code>{permalink}</code> — <?php echo esc_html__( 'post address', 'wp-scatter-elsewhere' ); ?></li>
				<li><code>{ordinal_day}</code> — <?php echo esc_html__( 'day of the month as written in the language of the site (1er, 1st, 2nd…), for example {ordinal_day} {date:F Y}', 'wp-scatter-elsewhere' ); ?></li>
				<li><code>{date}</code>, <code>{date:j F Y}</code> — <?php echo esc_html__( 'post date, with an optional PHP date format', 'wp-scatter-elsewhere' ); ?></li>
				<li><code>{author}</code> — <?php echo esc_html__( 'author name', 'wp-scatter-elsewhere' ); ?></li>
				<li><code>{categories}</code>, <code>{tags}</code>, <code>{terms:taxonomy}</code> — <?php echo esc_html__( 'terms of the post, separated by commas', 'wp-scatter-elsewhere' ); ?></li>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: "{{", 2: "}}". */
							__( '%1$s and %2$s write literal braces', 'wp-scatter-elsewhere' ),
							'{{',
							'}}'
						)
					);
					?>
				</li>
			</ul>
			<?php submit_button( __( 'Save templates', 'wp-scatter-elsewhere' ) ); ?>
		</form>
		<?php
	}

	private function renderRules(): void {
		$rules     = RulesFactory::settings()->rules();
		$playlists = RulesFactory::playlistChoices();
		$taxonomies = get_taxonomies( [ 'public' => true, 'show_ui' => true ], 'objects' );

		$rows = array_map( static fn( $rule ): array => $rule->toArray(), $rules );
		for ( $i = 0; $i < self::BLANK_RULE_ROWS; $i++ ) {
			$rows[] = [ 'taxonomy' => '', 'term' => '', 'playlist_id' => '', 'keyword' => '', 'include_children' => false ];
		}
		?>
		<h3><?php echo esc_html__( 'Playlists and keywords', 'wp-scatter-elsewhere' ); ?></h3>
		<p class="description"><?php echo esc_html__( 'The categories and tags of a post put its video in playlists and give it keywords. A row needs a playlist, a keyword, or both. Terms that are not listed are ignored. Nothing is removed when a post changes.', 'wp-scatter-elsewhere' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RULES ); ?>" />
			<?php wp_nonce_field( self::ACTION_RULES ); ?>
			<table class="widefat striped" style="max-width:60em">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Term', 'wp-scatter-elsewhere' ); ?></th>
						<th><?php echo esc_html__( 'Playlist', 'wp-scatter-elsewhere' ); ?></th>
						<th><?php echo esc_html__( 'Keyword', 'wp-scatter-elsewhere' ); ?></th>
						<th><?php echo esc_html__( 'Sub-terms', 'wp-scatter-elsewhere' ); ?></th>
						<th><?php echo esc_html__( 'Remove', 'wp-scatter-elsewhere' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $index => $row ) : ?>
						<tr>
							<td>
								<select name="rules[<?php echo esc_attr( (string) $index ); ?>][term]">
									<option value=""></option>
									<?php foreach ( $taxonomies as $taxonomy ) : ?>
										<optgroup label="<?php echo esc_attr( $taxonomy->labels->name ); ?>">
											<?php foreach ( (array) get_terms( [ 'taxonomy' => $taxonomy->name, 'hide_empty' => false ] ) as $term ) : ?>
												<?php if ( $term instanceof \WP_Term ) : ?>
													<option value="<?php echo esc_attr( $taxonomy->name . ':' . $term->slug ); ?>" <?php selected( $row['taxonomy'] . ':' . $row['term'], $taxonomy->name . ':' . $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
												<?php endif; ?>
											<?php endforeach; ?>
										</optgroup>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<?php if ( [] === $playlists ) : ?>
									<input type="text" class="regular-text code" name="rules[<?php echo esc_attr( (string) $index ); ?>][playlist_id]" value="<?php echo esc_attr( $row['playlist_id'] ); ?>" />
								<?php else : ?>
									<select name="rules[<?php echo esc_attr( (string) $index ); ?>][playlist_id]">
										<option value=""></option>
										<?php if ( '' !== $row['playlist_id'] && ! isset( $playlists[ $row['playlist_id'] ] ) ) : ?>
											<option value="<?php echo esc_attr( $row['playlist_id'] ); ?>" selected="selected"><?php echo esc_html( $row['playlist_id'] ); ?></option>
										<?php endif; ?>
										<?php foreach ( $playlists as $id => $title ) : ?>
											<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $row['playlist_id'], $id ); ?>><?php echo esc_html( $title ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
							</td>
							<td><input type="text" class="regular-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][keyword]" value="<?php echo esc_attr( $row['keyword'] ); ?>" /></td>
							<td><input type="checkbox" name="rules[<?php echo esc_attr( (string) $index ); ?>][include_children]" value="1" <?php checked( $row['include_children'] ); ?> title="<?php echo esc_attr__( 'Also apply to the posts that have a sub-term of this term', 'wp-scatter-elsewhere' ); ?>" /></td>
							<td><input type="checkbox" name="rules[<?php echo esc_attr( (string) $index ); ?>][remove]" value="1" /></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( [] === $playlists ) : ?>
				<p class="description"><?php echo esc_html__( 'The playlists of the channel could not be read (not connected, or YouTube refused): type the ID of a playlist instead.', 'wp-scatter-elsewhere' ); ?></p>
			<?php endif; ?>
			<?php submit_button( __( 'Save rules', 'wp-scatter-elsewhere' ) ); ?>
		</form>
		<?php
	}

	private function renderUploadSettings(): void {
		$settings = WordPressFactory::uploadSettings();
		$current  = $settings->defaultPrivacy();
		$licenses = [
			UploadSettings::LICENSE_YOUTUBE         => __( 'Standard YouTube License', 'wp-scatter-elsewhere' ),
			UploadSettings::LICENSE_CREATIVE_COMMON => __( 'Creative Commons - Attribution (CC BY)', 'wp-scatter-elsewhere' ),
		];
		$labels   = [
			UploadSettings::PRIVACY_PRIVATE  => __( 'Private (only you can see it)', 'wp-scatter-elsewhere' ),
			UploadSettings::PRIVACY_UNLISTED => __( 'Unlisted (anyone with the link)', 'wp-scatter-elsewhere' ),
			UploadSettings::PRIVACY_PUBLIC   => __( 'Public', 'wp-scatter-elsewhere' ),
		];
		?>
		<h3><?php echo esc_html__( 'Uploads', 'wp-scatter-elsewhere' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_UPLOAD ); ?>" />
			<?php wp_nonce_field( self::ACTION_UPLOAD ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpse-default-privacy"><?php echo esc_html__( 'Default privacy', 'wp-scatter-elsewhere' ); ?></label></th>
					<td>
						<select id="wpse-default-privacy" name="default_privacy">
							<?php foreach ( $labels as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php echo esc_html__( 'Videos are private by default so that tests never show on your channel. Google may keep videos private whatever you choose until your Google Cloud project has passed its API audit.', 'wp-scatter-elsewhere' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpse-default-license"><?php echo esc_html__( 'Default license', 'wp-scatter-elsewhere' ); ?></label></th>
					<td>
						<select id="wpse-default-license" name="default_license">
							<?php foreach ( $licenses as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings->defaultLicense(), $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpse-language"><?php echo esc_html__( 'Video language', 'wp-scatter-elsewhere' ); ?></label></th>
					<td>
						<input type="text" id="wpse-language" name="language" class="small-text code" value="<?php echo esc_attr( $settings->language() ); ?>" />
						<p class="description"><?php echo esc_html__( 'A language code such as fr, en or pt-BR. Leave empty to use the language of the site.', 'wp-scatter-elsewhere' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Thumbnail', 'wp-scatter-elsewhere' ); ?></th>
					<td>
						<label for="wpse-send-thumbnail">
							<input type="checkbox" id="wpse-send-thumbnail" name="send_thumbnail" value="1" <?php checked( $settings->sendsThumbnail() ); ?> />
							<?php echo esc_html__( 'Send the featured image of the post as the thumbnail of the video', 'wp-scatter-elsewhere' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Recording date', 'wp-scatter-elsewhere' ); ?></th>
					<td>
						<label for="wpse-recording-date">
							<input type="checkbox" id="wpse-recording-date" name="send_recording_date" value="1" <?php checked( $settings->sendsRecordingDate() ); ?> />
							<?php echo esc_html__( 'Send the date of the post as the recording date of the video', 'wp-scatter-elsewhere' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpse-subtitle-format"><?php echo esc_html__( 'Subtitle format', 'wp-scatter-elsewhere' ); ?></label></th>
					<td>
						<select id="wpse-subtitle-format" name="subtitle_format">
							<option value="sbv" <?php selected( $settings->subtitleFormat(), UploadSettings::SUBTITLE_FORMAT_SBV ); ?>><?php echo esc_html__( 'SubViewer (SBV, the YouTube format), converted from WebVTT', 'wp-scatter-elsewhere' ); ?></option>
							<option value="srt" <?php selected( $settings->subtitleFormat(), UploadSettings::SUBTITLE_FORMAT_SRT ); ?>><?php echo esc_html__( 'SubRip (SRT), converted from WebVTT', 'wp-scatter-elsewhere' ); ?></option>
							<option value="vtt" <?php selected( $settings->subtitleFormat(), UploadSettings::SUBTITLE_FORMAT_VTT ); ?>><?php echo esc_html__( 'WebVTT, sent as it is', 'wp-scatter-elsewhere' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Automatic captions', 'wp-scatter-elsewhere' ); ?></th>
					<td>
						<label for="wpse-remove-auto-captions">
							<input type="checkbox" id="wpse-remove-auto-captions" name="remove_auto_captions" value="1" <?php checked( $settings->removesAutomaticCaptions() ); ?> />
							<?php echo esc_html__( 'Delete the automatic captions of a language when subtitles are sent for it', 'wp-scatter-elsewhere' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'The deletion cannot be undone from this plugin. YouTube may refuse it.', 'wp-scatter-elsewhere' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save upload settings', 'wp-scatter-elsewhere' ) ); ?>
		</form>
		<?php
	}

	private function renderStatus( YouTubeSettings $settings, string $status ): void {
		if ( YouTubeSettings::STATUS_CONNECTED === $status ) {
			$channel = '' !== $settings->channelTitle() ? $settings->channelTitle() : __( '(unknown channel)', 'wp-scatter-elsewhere' );
			$message = sprintf(
				/* translators: 1: YouTube channel title, 2: date of the connection. */
				__( 'Connected to the channel %1$s on %2$s.', 'wp-scatter-elsewhere' ),
				$channel,
				wp_date( get_option( 'date_format' ), $settings->connectedAt() )
			);
			echo '<p>' . esc_html( $message ) . '</p>';
			return;
		}

		if ( YouTubeSettings::STATUS_NEEDS_REAUTH === $status ) {
			echo '<p>' . esc_html__( 'The authorisation has expired or was revoked: connect again.', 'wp-scatter-elsewhere' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'If your Google Cloud project is in "Testing" status, Google may expire authorisations after a short period. Publish the project to avoid this.', 'wp-scatter-elsewhere' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'Not connected.', 'wp-scatter-elsewhere' ) . '</p>';
	}

	private function renderNotice(): void {
		// A display-only query argument selecting one of the fixed messages below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';

		$messages = [
			'saved'        => __( 'Credentials saved.', 'wp-scatter-elsewhere' ),
			'connected'    => __( 'Connected to YouTube.', 'wp-scatter-elsewhere' ),
			'disconnected' => __( 'Disconnected from YouTube.', 'wp-scatter-elsewhere' ),
			'templates_saved' => __( 'Templates saved.', 'wp-scatter-elsewhere' ),
			'upload_saved'    => __( 'Upload settings saved.', 'wp-scatter-elsewhere' ),
			'rules_saved'     => __( 'Rules saved.', 'wp-scatter-elsewhere' ),
		];

		if ( isset( $messages[ $code ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $code ] ) . '</p></div>';
			return;
		}

		if ( 'error' === $code ) {
			$key     = self::ERROR_TRANSIENT . get_current_user_id();
			$message = get_transient( $key );
			delete_transient( $key );

			echo '<div class="notice notice-error"><p>' . esc_html( is_string( $message ) && '' !== $message ? $message : __( 'The operation failed.', 'wp-scatter-elsewhere' ) ) . '</p></div>';
		}
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-scatter-elsewhere' ), '', [ 'response' => 403 ] );
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
