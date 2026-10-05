<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# WP Scatter Elsewhere

A WordPress plugin that disperses the content and media published on a blog to
other places on the web.

## First target: YouTube

Publish the videos of a post to YouTube from WordPress: upload from the media
library, then set title, description, thumbnail, playlists, language and
subtitles automatically. See the
[YouTube publication workflow](docs/IA/youtube-publication-workflow.md).

## Configuration

Settings are on the **Settings → Scatter Elsewhere** page (administrators
only).

1. In the [Google Cloud console](https://console.cloud.google.com/), create a
   project, enable the *YouTube Data API v3* and create an OAuth client of type
   *Web application*. Add the redirect URI shown on the settings page.
2. Enter the client ID and the client secret, save, then click *Connect to
   YouTube*.

The client secret and the refresh token are stored in the WordPress options
table in clear text (not autoloaded), like other credentials stored by WordPress
plugins. Anyone who can read the database can use them. Google may expire
authorisations of a project left in *Testing* status after a short period;
publish the project to avoid this.

## Requirements

- WordPress 6.x or later.
- PHP 8.1 or later.

## License

GPL-3.0-or-later — see [LICENSE](LICENSE).
