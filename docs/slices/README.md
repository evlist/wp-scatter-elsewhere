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

18. [Update many videos at once](018-batch-update-videos.md): a WP-CLI command that applies the current settings and rules to the linked videos, field by field, with add or add-and-remove for playlists and keywords (implemented).
19. [Administration page: link the old posts](019-link-tools-page.md): the bulk linking of 017 in Tools, with a review table and undo (implemented).
20. [Administration page: update the videos](020-update-tools-page.md): the bulk update of 018 in Tools, with preview and a background run (implemented).
21. [Quota meter](021-quota-meter.md): the plugin counts the YouTube quota it spends and shows it in the settings, warns in the panels and limits the batch operations (counting, settings, panel notice and command implemented).
22. [Category from the term rules](022-category-from-rules.md): a third optional output of the rules, single-valued, most specific term wins (implemented).

Review and update:

23. [Review and edit the metadata before sending](023-review-and-edit-metadata.md): see what will be sent, change it, and keep the edits as overrides that later updates respect (planned).
24. [Update a video after its post changed](024-update-after-post-change.md): remember what was sent, tell when the post changed, and update from the panel or WP-CLI after a preview of the differences (planned).

A second target, Outdooractive (drafts, to be confirmed with the fields of the real form):

25. [Outdooractive: link a tour to a post](025-outdooractive-link-tour.md): record which tour tells the same hike as a post, without any access to Outdooractive (draft).
26. [Outdooractive: prepare the description of a tour](026-outdooractive-description.md): compose the fields of the description from the post, edit and copy them to paste in the tour (draft).
27. [Outdooractive: prepare the photos of a tour](027-outdooractive-photos.md): select, order and caption the images of a post and offer them as one download, to add to the tour (draft).

## Ideas to schedule

- An automatic push of the update when a post is saved (with the safeguards of the bulk tools).
- A column or filter in the list of posts showing the videos that are out of date.
- A restore function from the log of the bulk updates.
- The location of the video (coordinates per post).
- A browser-side helper that fills the editing form of an Outdooractive tour (only after slice 026, and after reading their terms of use).
- An official way to edit a tour (to ask Outdooractive's support or API team).
