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
6. [Thumbnail](006-thumbnail.md): the featured image, cropped to 16:9 and compressed, sent after an upload or on demand.
7. [Term rules](007-term-rules.md): playlists and keywords from taxonomy terms, one row per term with both optional, sub-terms optional.
8. [Language, license and recording date](008-language-license-recording-date.md): sent with uploads and applicable to a video already on YouTube.
9. [Subtitles](009-subtitles.md): WebVTT tracks of the page, converted to SBV, sent after an upload or on demand.
10. [Editor panel](010-editor-panel.md): after publication, choose the video, privacy and license, and follow the upload; the same panel in the sidebar of a published post.
11. (Merged into 010.) Sidebar button and status, to publish later or retry.
12. Locale-aware ordinal day placeholder `{ordinal_day}` (implemented): "1er" in French, "1st"/"2nd" in English, the plain number elsewhere.
13. [Additional parameters](013-additional-parameters.md): category, embeddable, public statistics, made for kids, notify subscribers (implemented; location is left out).

Linking posts that were published before the plugin to the videos that are already on YouTube:

14. [Link a video that is already on YouTube](014-link-existing-video.md): by address or id from the editor panel, with checks, confirmation and unlinking.
15. [Browse the videos of the channel](015-browse-channel-videos.md): a cached, searchable list of the channel to pick from (implemented).
16. [Suggest the video of a post](016-suggest-video-for-post.md): matching by the permalink left in the description by the old script, by title and by date. (implemented)
17. [Link all the old posts](017-link-existing-in-bulk.md): a WP-CLI command that reports first and links the confident matches on request. (implemented)

Updating and administration pages:

18. [Update many videos at once](018-batch-update-videos.md): a WP-CLI command that applies the current settings and rules to the linked videos, field by field, with add or add-and-remove for playlists and keywords (planned).
19. [Administration page: link the old posts](019-link-tools-page.md): the bulk linking of 017 in Tools, with a review table and undo (planned).
20. [Administration page: update the videos](020-update-tools-page.md): the bulk update of 018 in Tools, with preview and a background run (planned).
21. [Quota meter](021-quota-meter.md): the plugin counts the YouTube quota it spends and shows it in the settings, warns in the panels and limits the batch operations (counting, settings, panel notice and command implemented).
22. [Category from the term rules](022-category-from-rules.md): a third optional output of the rules, single-valued, most specific term wins (implemented).

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
