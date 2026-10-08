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

## Publishing from the editor

In the block editor, the **YouTube** panel appears after the post is published (and in the
document sidebar of a published post). It lists the videos found in the public page of
the post, lets you choose the privacy and the license (the defaults of the settings, so
private unless changed), and starts the upload with **Publish to YouTube**. It shows the
progress, the YouTube link with the privacy chosen at upload time (the **Check on YouTube**
button reads the real privacy, for about 1 unit of quota), the warnings, and a **Retry** button
when an upload failed. It
needs the `manage_options` capability (filter `wp_scatter_elsewhere_capability`). Uploads
run in the background with WP-Cron, see below.

### Videos that are already on YouTube

For posts published before the plugin, the panel offers **Link a video that is already on
YouTube** on every video that has no YouTube video yet: paste the address of the video (or its
ID), **Look up** shows its title, privacy and date, and **Link this video** records it. The video
must belong to the connected channel; if it is already linked to another post, the panel says
which one and asks before moving the link. **Unlink** removes a link (YouTube is never changed).
The same is available from WP-CLI:
`wp scatter-elsewhere link <post-id> <video-id> <youtube-url-or-id>` and
`wp scatter-elsewhere unlink <post-id> [<video-id>]`. Once linked, the old video can be completed
with `subtitles`, `thumbnail`, `playlists-add` and `apply-metadata`.

Instead of pasting an address, **Choose from my channel** lists the videos of the channel (title,
privacy, date, and the post each one is already linked to); type some words of the title to narrow
the list, then **Choose** goes through the usual confirmation. The list is kept for an hour
(**Refresh from YouTube** reads it again; reading costs about 1 quota unit per 50 videos plus 2).
**Suggest videos** proposes up to three videos of the channel for the post, with the reason: the
address of the post found in the description, the same title, or a similar title with a close
date (a video already linked elsewhere is never proposed). Nothing is linked before you confirm.
`wp scatter-elsewhere suggest <post-id>` prints the same candidates.
To link many old posts at once, `wp scatter-elsewhere link-existing [--post=<ids>] [--since=<date>]
[--limit=<n>] [--min-confidence=high|suggestion] [--pause=<ms>] [--format=<format>]` reads the channel
once, fetches the page of each post that mentions a video, and **only reports** the matches. Add
`--apply` to link those that reach the confidence (high by default, with the checks of the editor
link). A YouTube video wanted by several videos of the blog is never linked automatically, and the
report ends with the `unlink` command of everything that was linked.
From WP-CLI: `wp scatter-elsewhere channel-videos [--search=<words>] [--unlinked] [--limit=<n>] [--refresh]`.

## Uploading a video

The first entry point is WP-CLI (an editor interface will come later). The post
must be published, because the plugin reads its public page to find the videos.

```
wp scatter-elsewhere videos <post-id>
wp scatter-elsewhere upload <post-id> [<video-id>] [--privacy=private] [--now]
wp scatter-elsewhere jobs
wp scatter-elsewhere retry <job-id> [--now]
```

- Language (the site language by default), license (`youtube`, the standard YouTube
  license, by default; `creativeCommon` is the "Creative Commons - Attribution" license of
  YouTube Studio) and
  recording date (the date of the post, sent as noon UTC) are sent with the
  upload. Set them on the settings page. To apply them to a video that is already
  on YouTube:
  `wp scatter-elsewhere apply-metadata <post-id> [<video-id>] [--fields=language,license,recording_date]`.
- Category (22, People & Blogs, by default), embedding allowed, public statistics, "made for kids"
  and notification of the subscribers are also settings of the uploads; they apply to new uploads.
- Subtitles: the `<track>` files of the page (WebVTT) are sent after an upload,
  converted to SBV, the YouTube format, by default (setting *Subtitle format*: SBV, SRT or WebVTT as it is). A problem with the
  subtitles never fails the upload, it shows as a warning in `jobs`. To delete the automatic captions of the
  languages sent, enable the setting or use `--remove-auto`. To send or
  replace them later: `wp scatter-elsewhere subtitles <post-id> [<video-id>]`.
- Thumbnail: the featured image of the post is cropped to 16:9, scaled to 1280 x 720
  and compressed under 2 MB, then sent after an upload (setting *Thumbnail*). A
  problem never fails the upload, it shows as a warning in `jobs`. To send it later:
  `wp scatter-elsewhere thumbnail <post-id> [<video-id>]`. YouTube only allows custom
  thumbnails on verified channels.
- Playlists and keywords: on the settings page, a table gives each term (category, tag,
  ...) an optional playlist and an optional keyword, and a checkbox to apply the rule to
  the sub-terms too (off by default). The keywords are sent with the upload and the video
  is added to the playlists once uploaded (a problem is a warning in `jobs`). For a video
  already on YouTube: `wp scatter-elsewhere playlists-add <post-id>` and
  `wp scatter-elsewhere apply-metadata <post-id> --fields=keywords` (the keywords are
  added to the existing ones). `wp scatter-elsewhere playlists` lists your playlists.
  Nothing is removed and nothing is updated automatically when a post changes.
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
