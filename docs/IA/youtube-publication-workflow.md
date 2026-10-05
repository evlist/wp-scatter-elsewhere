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
2. A Python script run on the server calls
   [noviceiii/youtube-upload](https://github.com/noviceiii/youtube-upload) to
   upload the video:
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
| Playlists | to be defined (see open questions) | `playlistItems.insert` |
| Language | to be defined | `snippet.defaultLanguage`, `snippet.defaultAudioLanguage` |
| Subtitles | existing subtitle files on the blog | `captions.insert` |

## Design directions

- No shell at runtime (see [constraints](constraints.md)): the plugin talks to
  the YouTube Data API v3 directly from PHP instead of wrapping
  `youtube-upload`. The script remains a functional reference for the metadata
  rules, not a dependency.
- Uploads are long-running: they must run as background jobs with a visible
  status, never inside an editor request.
- A destination abstraction (connect, publish, status) keeps room for other
  destinations later; it is introduced only when a second destination exists,
  not speculatively.
- The YouTube video id and publication status are stored on WordPress so a
  video is never uploaded twice and can be linked or embedded afterwards.
- OAuth credentials are configured on the plugin settings page
  (`manage_options`), like other settings.
- The YouTube Data API has a daily quota; an upload is by far the most
  expensive call, so the job must handle quota errors and resume later.

## Open questions

1. Where is the current Python script, and may it be added to the repository
   as a reference for the description format?
2. Which playlists does a video go into, and how is that decided: a post
   category or tag, a post meta field, a choice in the editor?
3. Where does the video language come from: the site locale, a post language
   (Polylang, WPML, ...), a per-video setting?
4. In which format are the subtitles stored on the blog (where, and which
   format), and which language does each file have?
5. Trigger: button in the editor, automatic on post publication, or both?
6. Visibility of the YouTube video (public, unlisted, private) and whether
   the upload may be scheduled to match the post publication date.
7. After upload, should the plugin only store the YouTube id, or also replace
   the media file with a YouTube embed in the post?
8. A post may contain several videos; is one video per post enough for the
   first slice?
