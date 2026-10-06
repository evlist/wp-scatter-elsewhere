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

## Uploading a video

The first entry point is WP-CLI (an editor interface will come later). The post
must be published, because the plugin reads its public page to find the videos.

```
wp scatter-elsewhere videos <post-id>
wp scatter-elsewhere upload <post-id> [<video-id>] [--privacy=private] [--now]
wp scatter-elsewhere jobs
wp scatter-elsewhere retry <job-id> [--now]
```

- Language (the site language by default), license (`youtube` by default) and
  recording date (the date of the post, sent as noon UTC) are sent with the
  upload. Set them on the settings page. To apply them to a video that is already
  on YouTube:
  `wp scatter-elsewhere apply-metadata <post-id> [<video-id>] [--fields=language,license,recording_date]`.
- Subtitles: the `<track>` files of the page (WebVTT) are sent after an upload,
  converted to SBV, the YouTube format, by default (setting *Subtitle format*: SBV, SRT or WebVTT as it is). A problem with the
  subtitles never fails the upload, it shows as a warning in `jobs`. To delete the automatic captions of the
  languages sent, enable the setting or use `--remove-auto`. To send or
  replace them later: `wp scatter-elsewhere subtitles <post-id> [<video-id>]`.
- To see what YouTube holds for the subtitles of a video (state, failure reason,
  automatic or creator track): `wp scatter-elsewhere captions <post-id> [<video-id>]`.
- **Videos are private by default** (setting *Default privacy*), so tests do not
  show on your channel. Delete test videos in YouTube Studio.
- To the best of our knowledge, Google keeps the videos uploaded by a project
  that has not passed its API compliance audit private, whatever the requested
  privacy.
- Without `--now` the upload runs in the background with WP-Cron, which only runs
  when the site is visited. For a site with little traffic, call `wp-cron.php`
  from a system cron.
- An upload is the most expensive YouTube API call. With the default daily quota
  of a Google Cloud project, only a few uploads fit in a day, tests included.

## Requirements

- WordPress 6.x or later.
- PHP 8.1 or later.

## License

GPL-3.0-or-later — see [LICENSE](LICENSE).
