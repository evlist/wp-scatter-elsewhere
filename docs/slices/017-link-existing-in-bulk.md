<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Link all the old posts

## Summary

This slice links **many old posts at once**. A WP-CLI command reads the videos of the
channel and the posts that have a video, proposes the matches with their confidence (slice
016), and, when asked, records the confident ones. It is safe by default: it only reports
until told to apply.

## Context

A blog that has published videos for years has many posts to link. Doing it one by one in
the editor is long, while the old uploads left reliable traces (slice 016). Linking is
cheap and harmless (nothing is changed on YouTube), but a wrong link would make the plugin
think a post is already published, so the command reports before it acts.

## User story

As an administrator, I want to link all the posts that already have their video on YouTube
in one operation, after checking the proposed matches.

## Goals

- List the posts that have a video and no YouTube video, with the proposed match.
- Link in bulk the matches of high confidence, and only those, on request.
- Show what is left for a manual decision.
- Be resumable and kind to the quota and to the site.

## Functional requirements

### 1. The command

`wp scatter-elsewhere link-existing [--post=<ids>] [--since=<date>] [--limit=<n>] [--min-confidence=<level>] [--apply] [--format=<format>]`

- Without `--apply` (the default) it only reports.
- It reads the catalogue of the channel once (slice 015) and, for each candidate post, the
  videos of its public page (slice 003, one request per post, with a pause between posts).
- For each unlinked video of a post it computes the candidates (slice 016).
- The report has one row per video of a post: post, video of the blog, proposed YouTube
  video, strategy, confidence, and the decision ("would link", "needs a decision", "no
  candidate", "already linked").
- With `--apply`, the videos whose confidence reaches the minimum (high by default) are
  linked, with the checks of slice 014 (channel, not linked elsewhere). The others are
  left alone and listed.

### 2. Safety

- A YouTube video is never proposed for two videos of the blog in the same run: when two
  posts claim it, both are left for a decision.
- A video of the blog that already has a YouTube video is never touched.
- Everything linked in a run is listed at the end, with the command that unlinks it.
- The command refuses to run when the plugin is not connected.

### 3. Resuming and cost

- The posts are examined in the order of their date; `--since` and `--limit` split a long
  run, and posts that are already linked are skipped cheaply.
- The quota use is a few units for the catalogue; detection costs one HTTP request to the
  site per post, not quota.

### 4. Code structure

- `Matching/BulkLinker.php`: the pairing of all the posts with the catalogue, the conflict
  handling and the decisions, pure.
- `Cli/Command.php`: the command, its report and the pause between posts.

## Non-goals

- Linking without a report first, or linking suggestions of low confidence.
- An administration screen (possible follow-up: a page listing the unlinked posts with
  their suggestions and one-click confirmation).
- Updating the YouTube videos after linking (the existing commands `apply-metadata`,
  `subtitles`, `thumbnail` and `playlists-add`, and the update slices, do that).

## Acceptance criteria

1. Without `--apply` nothing is recorded, and the report says what would be done.
2. With `--apply` only the matches of the required confidence are linked, with the checks
   of slice 014.
3. A YouTube video claimed by two posts is linked to neither and listed for a decision.
4. A video that is already linked is skipped.
5. `--post`, `--since` and `--limit` restrict the posts examined.
6. The end of the report lists what was linked and how to undo it.
7. Unit tests cover the pairing, the conflicts and the decisions without WordPress.

## Notes

- Once linked, the old videos can be completed with the existing commands: for example the
  subtitles, the thumbnail and the playlists of the rules, which the old workflow set by hand.
