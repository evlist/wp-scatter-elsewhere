<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# YouTube connection

## Summary

This slice lets a site administrator connect the plugin to a YouTube channel
through Google OAuth 2.0, store the resulting credentials, and obtain valid
access tokens for the later slices. It delivers no upload yet: it delivers an
authorised, testable connection and the plumbing to keep it alive.

## Context

Every YouTube operation needs an OAuth access token for the channel owner. The
existing Python uploader used the out-of-band flow (`urn:ietf:wg:oauth:2.0:oob`),
which Google has retired, and kept its token in a file. A WordPress plugin used
by other sites must instead use a standard redirect flow whose callback is a
WordPress admin URL, and keep its state in WordPress options.

The first slices of the plugin follow the conventions of wp-media-helper: logic
in small classes with injected dependencies (closures for storage and HTTP), a
settings page under `manage_options`, translatable strings, and behaviour-
oriented unit tests that do not need WordPress.

## User story

As a site administrator, I want to authorise the plugin to publish on my
YouTube channel from the WordPress admin so that later slices can upload videos
without any command line or token file.

## Goals

- Let the admin enter the OAuth client id and client secret of their own Google
  Cloud project (each site uses its own project and quota).
- Run the authorisation-code flow with a redirect back to the WordPress admin.
- Persist the refresh token and connection metadata.
- Provide a service returning a valid access token, refreshing it when needed.
- Show the connection state and allow disconnecting.

## Functional requirements

### 1. Settings page

A **Scatter Everywhere** page under *Settings*, restricted to `manage_options`
(same trusted-admin model as wp-media-helper, see
[constraints](../IA/constraints.md)). It has a *YouTube* section with:

- **Client ID** and **Client secret** fields.
- A read-only **Redirect URI** to copy into the Google Cloud console
  (`admin-post.php?action=wp_scatter_everywhere_youtube_callback`, built with
  `admin_url()`).
- A **Connect** button, enabled once both credentials are saved.
- The connection state: *not connected*, *connected* (channel title and
  connection date) or *needs re-authorisation*.
- A **Disconnect** button when connected.

The client secret is never echoed back in the page: an already-saved secret
shows a placeholder, and leaving the field empty keeps the stored value.

### 2. Authorisation flow

1. **Connect** sends the browser to Google's authorisation endpoint with
   `response_type=code`, `access_type=offline`, `prompt=consent`, the redirect
   URI above, the scopes below and a `state` value.
2. `state` is a single-use random token stored for the current user (transient,
   short lifetime). The callback rejects a missing, expired or mismatching
   `state`.
3. The callback (`admin-post.php`, `manage_options` checked) exchanges the
   `code` at Google's token endpoint, stores the refresh token and redirects back
   to the settings page with a success or error notice.
4. After a successful exchange the plugin reads the authorised channel title
   (`channels.list`, `mine=true`) so the admin can verify the right account.

Scopes requested: `youtube.upload` and `youtube.force-ssl`, enough for upload,
thumbnail, playlists and captions. The exact scope list is confirmed against
the current API documentation when the slice is implemented, and is the single
place to change when later slices need more.

### 3. Access token service

An `AccessTokenProvider` returns a valid access token:

- the access token is cached with its expiry (a transient), and renewed with
  the refresh token when absent or within 60 seconds of expiry;
- a refresh answered with `invalid_grant` marks the connection as *needs
  re-authorisation* and raises a typed exception; other failures raise a
  different typed exception and leave the stored connection untouched;
- the provider never logs tokens or secrets.

### 4. Disconnect

Disconnect revokes the refresh token at Google's revocation endpoint
(best effort: a network failure does not prevent local removal), then deletes the
stored refresh token, cached access token and channel metadata. Client id and
secret are kept.

### 5. Persistence

A single option `wp_scatter_everywhere_youtube`:

```php
[
  'client_id'     => '1234-abc.apps.googleusercontent.com',
  'client_secret' => '…',
  'refresh_token' => '…',        // empty when not connected
  'connected_at'  => 1760000000, // Unix timestamp
  'channel_id'    => 'UC…',
  'channel_title' => 'My channel',
  'status'        => 'connected', // 'disconnected' | 'connected' | 'needs_reauth'
]
```

The option is created with `autoload` disabled. It holds secrets in clear text,
as WordPress itself does for other credentials; this is accepted for the
trusted-admin model and documented in the README. Moving secrets to constants
defined in `wp-config.php` is a possible later feature, not part of this slice.

### 6. Code structure

Under `plugin/includes/WP_Scatter_Everywhere/`:

- `Settings/YouTubeSettings.php`: load/save/normalise the option, with loader and
  saver closures like `ExternalSourceSettings`.
- `YouTube/OAuthClient.php`: build the authorisation URL, exchange a code,
  refresh a token, revoke a token. HTTP goes through an injected
  `Closure(string $url, array $form): array{status:int, body:array}`.
- `YouTube/AccessTokenProvider.php`: caching and refresh logic, with injected
  clock and cache closures.
- `Admin/SettingsPage.php` and `Admin/YouTubeCallback.php`: WordPress glue only.
- `Admin/Bootstrap.php` registers the above.

## Non-goals

- Uploading anything, or listing playlists (later slices).
- Several YouTube accounts or channels per site.
- Storing secrets outside the options table.
- Google verification of the OAuth app (the admin uses their own project).

## Acceptance criteria

1. An admin can save a client id and secret, and the secret is not displayed
   afterwards.
2. **Connect** redirects to Google with the expected parameters; the callback
   rejects an invalid `state` and a missing `code`.
3. A valid callback stores the refresh token and the channel title and shows
   *connected*.
4. `AccessTokenProvider` returns the cached token until 60 seconds before expiry,
   then refreshes it; `invalid_grant` yields *needs re-authorisation*.
5. **Disconnect** removes tokens locally even if revocation fails.
6. Users without `manage_options` cannot reach the page or the callback.
7. All user-facing strings use the `wp-scatter-everywhere` text domain.
8. Unit tests cover URL building, code exchange, refresh, expiry and error
   handling using fake HTTP closures.

## Notes

- For a Google Cloud project whose OAuth consent screen is in *Testing* status,
  Google may expire refresh tokens after a short period. The settings page
  explains this next to the *needs re-authorisation* state, and the README
  recommends putting the project in production for personal use.
- Quota is per Google Cloud project, so each site's own project gives it its own
  quota, which suits a plugin used by several sites.
