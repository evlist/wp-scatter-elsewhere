<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Update a video after its post changed

## Summary

This slice tells the author that **a post changed since its video was last sent to YouTube**, and
lets them **update the video in one click** from the editor panel (and from WP-CLI), at the price of
a few quota units. Nothing is pushed automatically: updating stays a decision of the author.

## Context

Once a post is published, it gets edited: a typo in the title, a longer excerpt, a new term. The
description of the video, built from the post, becomes stale and the author has no way to know,
other than comparing by hand. Updating a video costs about 50 quota units (an upload costs about
1,600), so the update is cheap, but it must remain deliberate: the author may have edited the
video in YouTube Studio, or only fixed something that does not concern it.

## User story

As an author, I want to see that the video of a post is out of date and to update it, after seeing
what would change.

## Goals

- Record what was last sent for each video of a post, and detect that the post now gives
  something different.
- Show the state in the panel ("Up to date", "The post changed since the video was updated",
  "Unknown") and offer an update with a preview of the differences.
- Update only the fields the author chooses; keep the edits of slice 023.
- Offer the same in WP-CLI and in the bulk tools ("only videos whose post changed").

## Functional requirements

### 1. What is remembered

For each linked video, the publication record gets `pushed` (the fingerprint) and `pushed_at`:

- The fingerprint is a hash of the **values the post gives**: title, description, language,
  recording date, category, keywords and the playlists of the rules (the effective values of slice
  023, including the overrides). The options that come from the settings only (embedding, public
  statistics, made for kids) and the privacy are not part of it.
- It is set when the video is **uploaded**, **updated** from the panel, the command or the bulk
  tools, and when a link is made (the metadata then is unknown: `pushed` stays empty, see 2).

### 2. The three states

- **Up to date**: the fingerprint of the current values equals the recorded one.
- **Changed**: they differ. The panel says when the video was last updated and offers **Update on
  YouTube**.
- **Unknown**: no fingerprint (a video linked by hand, or recorded before this slice). The panel
  offers **Check differences**, which compares with the video on YouTube (one read) and records the
  fingerprint when nothing differs.

The state is computed when the panel is loaded, from the post and the record, with no request to
YouTube; **Check differences** is the only action that reads YouTube.

### 3. The update from the panel

**Update on YouTube** opens the differences first, as the preview of slice 018 does (field, now on
YouTube, new value), reading the video once. The author ticks the fields to send, among the fields
of the post (title, description, language, recording date, category, keywords, playlists) and,
separately, the thumbnail and the subtitles; the options are remembered in the panel only. **Update**
sends them in one request and records the new fingerprint. If YouTube holds a title or description
that differs from both the recorded and the new value (edited in Studio), the preview marks the field
"changed on YouTube too" and leaves it unticked.

The review form of slice 023 is available from the same place, to edit before updating.

### 4. WP-CLI and the bulk tools

- `wp scatter-elsewhere update-videos --outdated` (and the same choice in the Tools page of slice
  020) selects only the videos whose fingerprint differs, which makes "update what changed" a cheap
  routine; videos in the "unknown" state are listed with a hint to run the check.
- `wp scatter-elsewhere check-videos [--post=<ids>] [--apply]` compares the unknown videos with
  YouTube and records the fingerprint of those that match, so that the states become known without
  updating anything.

### 5. Settings

- **Fields updated by the panel button** (default: title, description, keywords), so that the usual
  update is one click after the preview.
- No setting makes the update automatic. A filter `wp_scatter_elsewhere_update_on_save` is **not**
  provided in this slice: an automatic push would need the same safeguards as the bulk tools
  (quota, Studio edits, errors) and is left to a decision of its own.

### 6. Code structure

- `Publication/Fingerprint.php` (pure): the hash of the effective values, and the comparison.
- `Publication` and `PublicationStore` carry `pushed` and `pushed_at` (old records stay valid).
- `Update/PostUpdater.php`: the update of one video (reads, diffs with `VideoDiff`, marks the fields
  changed on YouTube too, applies, records the fingerprint) over the classes of slices 018 and 023.
- REST `GET /post/<id>/youtube/update-preview`, `POST .../update`, `POST .../check`; panel states
  and dialog in `assets/js/editor-youtube-panel.js`.

## Non-goals

- Updating automatically when the post is saved.
- Detecting that the video was changed on YouTube, other than for the fields of an update.
- Re-sending the video file (a new file is a new upload).
- Deleting or replacing videos on YouTube.

## Acceptance criteria

1. A post that changes after an upload shows "Changed"; an unchanged one shows "Up to date"; a
   video linked by hand shows "Unknown" until it is checked.
2. The state is computed without any request to YouTube.
3. The update shows the differences first, sends only the ticked fields, and records the new
   fingerprint; a field changed on YouTube too is unticked and marked.
4. The overrides of slice 023 are respected by the update and by the fingerprint.
5. `update-videos --outdated` selects only changed videos; `check-videos` records the fingerprint
   of those that already match.
6. Unit tests cover the fingerprint (what is and is not part of it), the three states, and the
   update with a field changed on YouTube too, without WordPress.

## Open questions

1. Whether the playlists should be part of the fingerprint: they are only ever added or, in sync
   mode, removed by the rules, so a change of terms does change what the post gives; they are
   included, and the update of the playlists follows the mode chosen in the panel (add by default).
2. Whether a stale video should also be flagged in the list of posts of the admin (a column or a
   filter); it would need the state of many posts at once and is left for later.
