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
5. Publication state: store the YouTube id and status; prevent double uploads; expose the YouTube URL (template function, block or shortcode).
6. Thumbnail from the post featured image.
7. Term rules table: playlists and keywords from taxonomy terms.
8. Video language, license and recording date.
9. Subtitles (WebVTT).
10. Post-publish panel: after publication, choose the video, privacy and license.
11. Sidebar button and status, to publish later or retry.
12. Locale-aware ordinal date placeholder.
13. Additional parameters (category, location, ...).
