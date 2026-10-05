<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# YouTube publication workflow

First target of the plugin: publish the videos of a blog post to YouTube.
The plugin is meant to disperse blog content to many destinations over time,
so YouTube is modelled as the first *destination*, not as the core of the
plugin.

## Current workflow (manual and scripted)

1. The video file lives in a directory mounted under the uploads directory and
   is registered in the WordPress media library (for example with
   [wp-media-helper](https://github.com/evlist/wp-media-helper)). The plugin
   therefore starts from a video attachment in the media library.
2. A Python script run on the server uploads the video through the YouTube
   Data API (a `youtube-upload.py` wrapper built on `google-api-python-client`,
   which is itself called by a script that builds the metadata):
   - title: the post title;
   - description: publication date, post excerpt and a link to the post.
3. Manually, in YouTube Studio:
   - set the post's featured image as the video thumbnail;
   - add the video to playlists;
   - set the video language;
   - upload subtitles, which already exist on the blog in another format.

## Target workflow

All the steps above are performed by the plugin from WordPress, without the
Python script, the shell or YouTube Studio:

| Step | Source in WordPress | YouTube API resource |
|------|---------------------|----------------------|
| Upload | video attachment file | `videos.insert` (resumable upload) |
| Title | post title | `snippet.title` |
| Description | publication date, excerpt, post permalink | `snippet.description` |
| Thumbnail | post featured image | `thumbnails.set` |
| Playlists | post categories mapped to playlists in the settings | `playlistItems.insert` |
| Language | site language | `snippet.defaultLanguage`, `snippet.defaultAudioLanguage` |
| Subtitles | existing WebVTT files on the blog | `captions.insert` |
| Privacy | asked at publication, `public` by default | `status.privacyStatus` |
| License | asked at publication, with a default in the settings | `status.license` |
| Recording date | post date (see decisions) | `recordingDetails.recordingDate` |

## Decisions

- **Playlists:** an editable *category → playlist* table on the settings page.
  A post category absent from the table is ignored; a category present adds the
  video to the matching playlist.
- **Language:** the site language.
- **Subtitles:** WebVTT files already published on the blog. If YouTube needs
  a conversion, the plugin performs it (to be verified against the API when the
  slice is written).
- **Trigger:** the user is asked at publication time (pre-publish panel in the
  block editor), and a button in the editor lets them publish to YouTube
  afterwards if they did not do it then.
- **Choices at publication:** the video to use when the post contains several
  (one video per run, as in the current script), the privacy status (`public`
  by default) and the license.
- **Scheduling:** not used. Posts about a past hike are back-dated, so the post
  date is the *event* date and is not a publication schedule. The plugin never
  maps the post date to `publishAt`. It may send it as the video recording date.
- **Other parameters:** the script exposes more (tags, category, location,
  made for kids, public statistics, targeting). They are lower priority and are
  added after the core flow, as settings with defaults, overridable at
  publication when it makes sense.

## Design directions

- No shell at runtime (see [constraints](constraints.md)): the plugin talks to
  the YouTube Data API v3 directly from PHP. The existing Python script is the
  functional reference (resumable upload with exponential back-off, thumbnail,
  playlist, token refresh).
- Authorisation cannot use the `urn:ietf:wg:oauth:2.0:oob` flow of the script,
  which Google has retired. The plugin uses a standard redirect to a WordPress
  admin URL and stores the refresh token itself.
- Uploads are long-running: they run as background jobs with a visible
  status, never inside an editor request.
- The YouTube video id and publication status are stored on WordPress so a
  video is never uploaded twice and can be linked or embedded afterwards.
- OAuth credentials and the category → playlist table live on the plugin
  settings page (`manage_options`).
- YouTube is the first *destination*; a destination abstraction is introduced
  only when a second one exists.
- The YouTube Data API has a daily quota and an upload is the most expensive
  call: the job handles quota errors and resumes later.

## Open questions

1. **Description template:** the script provided is the uploader, not the one
   that builds the description. What is the exact format (date format, excerpt,
   link wording, language)? Is it translatable?
2. **Subtitles location:** how are the VTT files attached to a post (media
   library attachments, a block, a meta field) and how is each file's language
   known?
3. **Tags and category:** are YouTube keywords taken from post tags, and is the
   YouTube category always 22 (People & Blogs)?
4. **Recording date:** should the post date be sent as the recording date?
5. **Embedding:** after upload, should the plugin only store the YouTube id, or
   also offer to replace the media file by an embed in the post?
