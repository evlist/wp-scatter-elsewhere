<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slices

Small vertical slices, delivered in order. Detailed slice documents are written
when a slice is about to start. Tentative plan for the YouTube destination (see
[YouTube publication workflow](../IA/youtube-publication-workflow.md)):

1. [YouTube connection](001-youtube-connection.md): OAuth credentials, authorisation and access tokens.
2. [Metadata templates](002-metadata-templates.md): title and description templates with placeholders (pure, unit-tested).
3. [Video and subtitle detection](003-video-and-subtitle-detection.md): videos and `<track>` subtitles read from the rendered page; parsing and file mapping are pure, fetching is a thin layer.
4. Video upload: resumable upload of a detected video as a background job.
5. [Publication state](005-publication-state.md): store the YouTube id on the post, prevent double uploads, expose the YouTube link (template function and shortcode).
6. Thumbnail from the post featured image.
7. Term rules table: playlists and keywords from taxonomy terms.
8. [Language, license and recording date](008-language-license-recording-date.md): sent with uploads and applicable to a video already on YouTube.
9. Subtitles (WebVTT).
10. Post-publish panel: after publication, choose the video, privacy and license.
11. Sidebar button and status, to publish later or retry.
12. Locale-aware ordinal date placeholder.
13. Additional parameters (category, location, ...).

## Ideas to schedule

Points raised during the first real upload, not yet planned as slices:

- **Update an existing video**: after a published post is modified, push the new
  title, description and other metadata to the YouTube video already linked to it
  (`videos.update`, much cheaper in quota than an upload). Probably needs a way to
  tell that the post changed since the last push, and to be triggered like uploads
  (editor button, WP-CLI).
- **Review and edit the metadata before the upload**: an optional step that shows
  the composed title, description and other metadata, and lets the author change
  them before they are sent. It should work for uploads and for updates.
