<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# YouTube publication workflow

First target of the plugin: publish the videos of a blog post to YouTube.
The plugin is meant to disperse blog content to many destinations over time,
so YouTube is modelled as the first *destination*, not as the core of the
plugin. It must also be usable by other sites: nothing specific to one blog's
theme, file layout or language may be hard-coded.

## Current workflow (reference)

1. A video file lives in a directory mounted under the uploads directory and is
   registered in the WordPress media library (for example with
   [wp-media-helper](https://github.com/evlist/wp-media-helper)).
2. A Python script (`wp_youtube_upload.py`) reads the post from the WordPress
   REST API, then runs a second script (`youtube-upload.py`, built on the YouTube
   Data API) with:
   - title: the post title;
   - description: `<date>, <excerpt> Détails : <permalink>`, where the date is
     the post date formatted in the site language;
   - language `fr` and license `creativeCommon` (defaults);
   - the video file, found by date in an external directory; when several
     videos match, the user picks one.
   The uploader also supports thumbnail, playlist, tags, category, location,
   privacy, scheduling and a few other parameters; the wrapper only uses the
   first ones.
3. Manually, in YouTube Studio: thumbnail (the post's featured image), playlists,
   language, subtitles (WebVTT converted to the format Studio accepts). The
   recording date could not be set in Studio.

## Target workflow

All of the above is done by the plugin from WordPress, without scripts, shell
or YouTube Studio:

| Step | Source in WordPress | YouTube API resource |
|------|---------------------|----------------------|
| Upload | video found in the post (see below) | `videos.insert` (resumable upload) |
| Title | configurable template, default `{title}` | `snippet.title` |
| Description | configurable template using post elements | `snippet.description` |
| Thumbnail | post featured image | `thumbnails.set` |
| Playlists | taxonomy term → playlist rules | `playlistItems.insert` |
| Keywords | taxonomy term → keyword rules | `snippet.tags` |
| Language | site language (setting default) | `snippet.defaultLanguage`, `snippet.defaultAudioLanguage` |
| Subtitles | WebVTT tracks of the video | `captions.insert` |
| Privacy | asked at publication, `public` by default | `status.privacyStatus` |
| License | asked at publication, setting default | `status.license` |
| Recording date | post date | `recordingDetails.recordingDate` |

## Decisions

- **Title and description** are templates with placeholders resolved from the
  post, defaulting to the post title and the description format above. Proposed
  syntax: named placeholders in braces, with an optional format after a colon,
  as already used by wp-media-helper path patterns, for example
  `{date:j F Y}, {excerpt} Détails : {permalink}`. Dates are formatted with the
  site locale. A positional `printf` syntax (`%1$s`) was considered less
  readable for end users and is not planned. Initial placeholders: `title`,
  `excerpt`, `permalink`, `date:<format>`, `author`, plus term lists such as
  `categories` and `tags`.
- **Term rules (playlists and keywords):** an editable table in the settings, one row
  per taxonomy term (categories, tags, any taxonomy), with an optional playlist and an
  optional keyword: a row can produce both. A term absent from the table is ignored.
  See [slice 007](../slices/007-term-rules.md).
- **Finding videos and subtitles:** by reading the video elements of the
  rendered public page of the post, not by a naming convention on attachments.
  `<video>` / `<source>` give the files, `<track kind="subtitles"
  srclang="…">` gives each subtitle file and its language. Each `src` is mapped
  back to a media-library attachment (or an uploads file). The detection sits
  behind a small interface so other strategies (such as the `foo-fr.vtt` suffix
  convention, or parsing the stored content) can be added without touching the
  rest. See *Video detection* below.
- **Language:** the site language by default.
- **Trigger:** the post must be published first: the description contains the
  post permalink and the page must be publicly reachable to detect the videos.
  The user is therefore asked *after* publication (post-publish panel of the
  block editor), and a button in the editor sidebar lets them publish to
  YouTube later if they declined or postponed. When the page holds several
  videos, the user chooses which one (one video per run).
- **Choices at publication:** the video, the privacy status (`public` by
  default) and the license (default from the settings).
- **Scheduling:** not used. Posts about a past event are back-dated, so the post
  date is the *event* date and is never mapped to `publishAt`; it is sent as
  the recording date instead.
- **After upload:** the blog remains the origin and keeps serving the video. The
  plugin stores the YouTube video id (post meta, per video) and offers the
  YouTube URL for display: a template function and a block/shortcode, so a theme
  or the author can add an "also on YouTube" link. Automatic insertion is an
  optional setting, not the default.
- **Other parameters** (category, location, made for kids, public statistics,
  targeting): lower priority, added later as settings with defaults.

- **To schedule later:** updating the YouTube video of a post that was modified
  after publication, and an optional review/edit of the metadata before an upload
  or an update (see `docs/slices/README.md`). Language, license, thumbnail and
  playlists are designed as operations that can also be applied to a video that is
  already on YouTube, because an update costs far less quota than an upload.

## Video detection

The theme adds videos and subtitle tracks at display time from attachments, so
they exist in the final page but not in the stored post content. Since YouTube
publication happens after the post is published, the plugin fetches the
rendered public page of the post (server-side loopback request) and parses the
`<video>`, `<source>` and `<track>` elements.

Consequences to handle explicitly:

- the fetch can fail (loopback blocked, authentication in front of the site,
  caching): the error is reported and the user can retry;
- password-protected, private and non-public posts are not reachable and are
  not eligible, which also matches the intent of publishing publicly;
- a `src` that does not map to a local file (external video) is listed but not
  uploadable.

## Design directions

- No shell at runtime (see [constraints](constraints.md)): the plugin talks to
  the YouTube Data API v3 directly from PHP. The existing uploader is the
  functional reference (resumable upload with exponential back-off, thumbnail,
  playlist, token refresh). Its `recordingDetails` is nested in `snippet`; in
  the API it is a separate resource part, which the plugin must respect.
- Authorisation cannot use the `urn:ietf:wg:oauth:2.0:oob` flow of the script,
  which Google has retired. The plugin uses a standard redirect to a WordPress
  admin URL and stores the refresh token itself.
- Uploads are long-running: they run as background jobs with a visible
  status, never inside an editor request.
- A video is never uploaded twice; the stored id and status prevent it.
- OAuth credentials, templates and term rules live on the plugin settings page
  (`manage_options`).
- The YouTube Data API has a daily quota and an upload is the most expensive
  call: the job handles quota errors and resumes later.
- YouTube is the first destination; a destination abstraction is introduced
  only when a second one exists.

## Open questions

1. **Subtitle format:** WebVTT is what the blog serves. The plugin will check
   whether `captions.insert` accepts it directly; if not, it converts.
2. **Ordinal dates (done, slice 012 `{ordinal_day}`):** a locale-aware ordinal placeholder (such as
   "1er" in French) is wanted, after the core flow. Its design is left open
   (a `{date:…}` format extension or a dedicated placeholder).
