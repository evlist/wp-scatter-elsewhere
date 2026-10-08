<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Review and edit the metadata before sending

## Summary

This slice lets the author **see what will be sent to YouTube and change it** before an upload
or an update: the title and description composed from the templates, the language, license,
privacy, recording date, category, keywords and playlists, the thumbnail and the subtitles. What
the author changes is kept as **overrides of that video**, so that later updates, and the bulk
updates of slices 018 and 020, do not silently put the template values back.

## Context

The templates and the rules give a good result most of the time, but not always: a title that
needs a word, a description that needs a paragraph about this particular video, a keyword that
does not come from a term. Today the only way is to upload and fix it in YouTube Studio, which
the next update would then overwrite. The review is **optional**: the one-click upload of slice
010 stays the default path.

## User story

As an author, I want to read the title, description and other metadata that will be sent, fix
what is wrong, and have my changes respected afterwards.

## Goals

- Show the composed metadata of a video of a post before it is sent, with the problems YouTube
  would complain about (title over 100 characters, description over 5,000 bytes, characters it
  refuses, keywords that do not fit).
- Let the author edit the fields and send the result, for an upload and for an update.
- Keep the edits as overrides per video of a post, visible and removable.
- Make every automatic path (bulk update, `apply-metadata`, later updates) respect them.

## Functional requirements

### 1. The preview

A **Review before sending** link in the editor panel, next to **Publish to YouTube** (and next to
**Update on YouTube** of slice 024), opens a form pre-filled with the composed values:

| Field | Control |
|-------|---------|
| Title | Text, with a counter (100 characters). |
| Description | Text area, with a counter (5,000 bytes). |
| Privacy, license | The selects of the panel. |
| Language | Text (language code). |
| Recording date | Date. |
| Category | Number or select of the usual categories. |
| Keywords | Tag-like list, with the characters left of the 500. |
| Playlists | Multi-select of the playlists of the channel. |
| Thumbnail, subtitles | Read-only: the image and the tracks that will be sent, with the reason when something cannot be sent. |

Each field shows whether its value is **composed** (templates and rules) or **edited**, with a
**Reset** to the composed value. Warnings are shown beside the field, not in a pop-up.

### 2. Sending

**Send** uploads (or updates) with the values of the form. The server validates them again with
the same normalisers as the composed values (`YouTubeTextNormalizer`, `KeywordNormalizer`,
the privacy and license lists) and refuses what YouTube would refuse, with a message per field.
A setting **Review before every upload** makes the form the default step of **Publish to YouTube**;
it is off by default.

### 3. Overrides

- What differs from the composed value when sending is stored as the overrides of that video of
  the post (post meta `_wp_scatter_elsewhere_youtube_overrides`, keyed by the video ID, only the
  edited fields).
- The metadata builder applies the overrides on top of the composed values, so that every path
  that builds metadata gets them: the upload, `apply-metadata`, the bulk update, the update of
  slice 024.
- The panel shows "Edited by hand: title, keywords" with **Show** (the review form) and **Forget
  the edits**. An override that equals the composed value is dropped.
- The bulk update (slices 018 and 020) takes an option **Ignore the edits** (`--ignore-overrides`)
  to put the composed values back deliberately; without it the edited fields are left as the
  author wanted and the report says so.
- Keywords and playlists: an edit replaces the composed list for that video; "sync" removal of
  slice 018 only removes keywords and playlists managed by the rules, so a keyword added by hand in
  the form is kept either way.

### 4. REST and WP-CLI

- `GET /post/<id>/youtube/metadata?video_id=` returns the composed values, the overrides, the
  effective values and the warnings. `POST /post/<id>/youtube/upload` and
  `POST /post/<id>/youtube/update` accept an `overrides` object. `DELETE .../overrides` forgets them.
- `wp scatter-elsewhere metadata <post-id> [<video-id>]` prints the effective metadata with the
  origin of each field; `upload` and `apply-metadata` take `--set=<field>=<value>` (repeatable) to
  override from the command line, and `--reset=<field>`.

### 5. Code structure

- `Metadata/Overrides.php` (pure): validation and normalisation of an override set, the merge
  over `VideoMetadata`, the comparison with the composed values (which fields are really edited).
- `Metadata/OverrideStore.php`: post meta storage by video.
- `Metadata/MetadataWarnings.php` (pure): the problems of a set of values.
- `VideoMetadataBuilder::build()` gets the overrides of the video (an optional argument filled by
  the WordPress factory), without changing its tests for the composed values.
- `Editor/MetadataReview.php`: the state sent to the panel; REST routes in `YouTubeController`;
  the form in `assets/js/editor-youtube-panel.js` (or a separate script loaded by the panel).

## Non-goals

- Editing the templates for one video (the edited value is the result, not a template).
- Rich-text editing of the description (YouTube descriptions are plain text).
- Editing the thumbnail or the subtitles in the form (the featured image and the tracks of the page
  remain the source).
- Reviewing the bulk operations one video at a time (their preview shows the differences).

## Acceptance criteria

1. The form shows the composed metadata, the counters and the warnings for what YouTube refuses.
2. An upload or an update sends the edited values; invalid values are refused with a message per
   field before any request to YouTube.
3. The edits are stored per video of the post; a value equal to the composed one is not stored.
4. The next update, `apply-metadata` and the bulk update keep the edited fields, unless
   explicitly told to ignore the edits.
5. The panel says which fields are edited and can forget the edits.
6. Unit tests cover the validation, the merge, the detection of the edited fields and the
   warnings without WordPress; the form is covered by the jsdom harness.

## Open questions

1. Whether the overrides should also be offered in the Tools pages (an "edited" column in the
   preview of slice 020 is enough to start).
2. Whether an edited description should keep following the `{permalink}` of the post if the
   permalink changes (an edit is a literal text, so it does not; the review form can show the
   placeholders again by resetting the field).
