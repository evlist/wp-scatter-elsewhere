<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Update many videos at once (WP-CLI)

## Summary

This slice adds a WP-CLI command that **updates the YouTube videos linked to many posts** so
that they match the current settings and rules: a new default license, a new category, new
playlist or keyword rules, a changed template, and so on. It reports the differences first,
changes only what differs, and lets the administrator choose **which parameters** are updated and,
for the parameters that are lists (playlists, keywords), whether the command **only adds** or also
**removes** what no longer applies.

## Context

Today `apply-metadata`, `playlists-add`, `subtitles` and `thumbnail` work on one post at a time.
After a change of the defaults (the license, the category, a playlist rule, ...) the videos
already on YouTube keep the old values, and updating a hundred of them by hand is not realistic.
Updating a video costs little quota (about 50 units for `videos.update`, 1 to read), far less
than an upload (about 1600), so a bulk update is affordable, but it must be deliberate: a
template change would rewrite all the titles and descriptions.

## User story

As an administrator, I want to apply a changed setting or rule to all the videos already on
YouTube, after checking what will change, and to say whether the playlists and keywords that no
longer apply are removed.

## Goals

- Select the posts (all, by id, date, term) whose videos are linked to YouTube.
- Say which parameters are updated; nothing else is touched.
- Show, for each video, what would change (old value, new value), and send only the differences.
- For list parameters, choose between **add** and **sync** (add and remove).
- Be safe by default (report only), resumable, and kind to the quota.

## Functional requirements

### 1. The command

`wp scatter-elsewhere update-videos [--post=<ids>] [--since=<date>] [--until=<date>] [--term=<taxonomy:slug>] [--limit=<n>] [--fields=<list>] [--playlists=<add|sync>] [--keywords=<add|sync>] [--privacy=<value>] [--apply] [--quota-limit=<units>] [--pause=<ms>] [--format=<format>]`

- Without `--apply` it only reports. The default is deliberately the safe one.
- The posts are the ones that have a linked YouTube video (publication records and finished
  uploads), oldest first. `--post`, `--since`, `--until`, `--term` and `--limit` restrict them.
  `--term=category:vanlife` also accepts the sub-terms when the taxonomy is hierarchical.

### 2. The parameters (`--fields`)

A comma-separated list among the following; the default is **none** (the command refuses to
guess), and `all-safe` stands for every field except `title`, `description` and `privacy`.

| Field | What is compared and sent |
|-------|---------------------------|
| `title`, `description` | The result of the templates for the post. |
| `language` | The language of the settings or of the site. |
| `license` | The default license of the settings. |
| `recording_date` | The date of the post. |
| `category` | The category of the settings (a single value: it is replaced). |
| `embeddable`, `public_stats`, `made_for_kids` | The options of the settings. |
| `keywords` | The keywords given by the rules for the terms of the post. |
| `playlists` | The playlists given by the rules for the terms of the post. |
| `thumbnail` | The featured image, prepared as for an upload. |
| `subtitles` | The tracks of the page, sent as for an upload. |
| `privacy` | Only with `--privacy=<value>`: never derived from the defaults, because a bulk change of privacy publishes or hides videos. |

The notifications of subscribers are not a property of a video and have no meaning here.

### 3. Adding and removing (`--playlists`, `--keywords`)

- `add` (default): what the rules give is added; nothing is ever removed.
- `sync`: what the rules give is added **and what the rules would have given but no longer
  applies is removed**.
- Only what the plugin manages is removed. For playlists, the managed set is the playlists named
  by at least one term rule: a playlist that no rule mentions (a manual playlist) is never
  touched. For keywords, the managed set is the keywords of all the rules: keywords typed by
  hand on YouTube stay. This is what makes `sync` safe with videos that were also edited by hand.
- Removing a video from a playlist needs the id of the playlist item (a `playlistItems.list`
  request, then `playlistItems.delete`, about 50 units); it is done only in `sync` mode.
- Single-valued parameters (language, license, category, ...) are simply replaced when selected.

### 4. The report

One row per video and per field that differs: post, video, YouTube id, field, current value,
new value, action ("would update", "updated", "unchanged", "skipped: no value", "error").
Videos that already match produce one "unchanged" row only with `--format=json` or a verbose
flag, to keep the report short. The end of the report gives the number of videos, of changes, and
the **estimated quota** (read 1 unit per video, 50 per update, 50 per playlist addition or
removal, about 400 per captions insert).

### 5. Safety, resuming and cost

- `--quota-limit` stops the run before the estimate of the next video would exceed it (the
  default daily quota of YouTube is 10,000 units). The run is **idempotent**: a second run finds
  nothing left to change for the videos done and goes on with the others, so there is no
  separate state to keep.
- A video that cannot be updated (deleted on YouTube, authorisation) is reported and the run
  continues; the exit status says that errors occurred.
- A video that does not exist anymore on YouTube is reported with the hint to `unlink` it.
- The thumbnail and subtitle updates are slow; they are only done when explicitly selected.
- Nothing is changed on the blog; the publication records are untouched (the privacy is
  refreshed from YouTube after an update that changes it).

### 6. Code structure

- `Update/DesiredState.php`: what the video should look like for a post, built from the
  existing metadata builder, the settings and the rules; pure.
- `Update/CurrentState.php`: what YouTube has (read with `videos.list`, and the managed
  playlists through `playlistItems`).
- `Update/VideoDiff.php`: compares the two for the selected fields, with the add/sync modes and
  the managed sets; pure, the core of the tests.
- `Update/BatchUpdater.php`: applies a diff with the existing `VideoUpdater`, `PlaylistClient`
  (extended with item ids and removal), `ThumbnailService` and `SubtitleService`; the
  quota estimate.
- `Cli/Command.php`: `update-videos`, selection, report, pause.

The same classes serve the administration page of slice 020.

## Non-goals

- Updating automatically when a post or a setting changes (the command is explicit).
- Editing values one by one before sending (the review step of the ideas list).
- Creating playlists, or deleting videos on YouTube.
- Changing the privacy from the defaults.

## Acceptance criteria

1. Without `--apply` nothing is sent to YouTube (only reads) and the report lists the
   differences.
2. Only the selected fields are compared and sent; the other properties of the video are kept.
3. A field whose value already matches is not sent, and a video with no difference costs one read.
4. `--playlists=add` never removes; `--playlists=sync` removes from the playlists named by the
   rules those that the rules no longer give, and never touches a playlist that no rule names.
   The same for `--keywords`.
5. `--fields` is required, an unknown field is refused, `privacy` requires `--privacy`.
6. `--quota-limit` stops the run and says where; running again continues.
7. Unit tests cover the diff of each field, the add and sync modes with managed and unmanaged
   playlists and keywords, and the quota estimate, without WordPress.

## Open questions

1. Should `sync` of playlists also be offered for the playlists given by the *default* (a future
   "always add to playlist X" setting)? Not needed for now: the rules are the only source.
2. Should the category become a rule output (a category per term, like the playlists)? It is a
   natural follow-up: single-valued, so the first matching rule would win.
