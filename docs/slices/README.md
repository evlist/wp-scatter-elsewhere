<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slices

Small vertical slices, delivered in order. Detailed slice documents are written
when a slice is about to start. Tentative plan for the YouTube destination (see
[YouTube publication workflow](../IA/youtube-publication-workflow.md)):

1. YouTube connection: OAuth credentials and authorisation on the settings page.
2. Metadata composition: title and description templates with placeholders (pure, unit-tested).
   Detection of videos and subtitles in the rendered page (`<video>`, `<source>`, `<track>`) behind an interface: parsing is pure and unit-tested, fetching is a separate thin layer.
3. Video upload: resumable upload of a media-library video as a background job.
4. Publication state: store the YouTube id and status; prevent double uploads; expose the YouTube URL (template function, block or shortcode).
5. Thumbnail from the post featured image.
6. Term rules table: playlists and keywords from taxonomy terms.
7. Video language, license and recording date.
8. Subtitles (WebVTT).
9. Post-publish panel: after publication, choose the video, privacy and license.
10. Sidebar button and status, to publish later or retry.
11. Locale-aware ordinal date placeholder.
12. Additional parameters (category, location, ...).
