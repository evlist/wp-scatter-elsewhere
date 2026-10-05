<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slices

Small vertical slices, delivered in order. Detailed slice documents are written
when a slice is about to start. Tentative plan for the YouTube destination (see
[YouTube publication workflow](../IA/youtube-publication-workflow.md)):

1. YouTube connection: OAuth credentials and authorisation on the settings page.
2. Metadata composition: title and description from a post (pure, unit-tested).
3. Video upload: resumable upload of a media-library video as a background job.
4. Publication state: store the YouTube id and status; prevent double uploads.
5. Thumbnail from the post featured image.
6. Playlists from a category → playlist settings table.
7. Video language, license and recording date.
8. Subtitles (WebVTT).
9. Pre-publish panel: choose the video, privacy and license when publishing.
10. Post-publication editor button and status.
11. Additional parameters (tags, category, location, ...).
