<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Suggest the video of a post

## Summary

This slice **suggests the YouTube video that matches a post**, so that linking an old
post is usually one click. The matching uses what the old workflow left on YouTube: the
description of the videos contains the address of the post, and their title is the title
of the post.

## Context

The script that uploaded the videos before the plugin used the title of the post as the
title of the video, and a description made of the date, the excerpt and `Détails :`
followed by the **permalink of the post**. That permalink is an exact key. When it is
missing, the title and the dates still give good candidates.

## User story

As an author, I want the panel to propose the right YouTube video for an old post so that
I only have to confirm it.

## Goals

- Match a post to a video of the channel with confidence levels.
- Never link anything without confirmation (the automatic linking is for the bulk slice,
  and explicit there).
- Keep the matching a pure, tested piece, usable by the panel and by WP-CLI.

## Functional requirements

### 1. Strategies, in order of confidence

1. **Permalink in the description** (high confidence): a YouTube description that holds
   the permalink of the post (compared without scheme, with or without trailing slash,
   and decoded) designates that post.
2. **Exact title** (high confidence when unique): the title of the video equals the title
   of the post, ignoring case, accents, punctuation and spaces. Several candidates make
   it a suggestion, not a certainty.
3. **Similar title and close date** (suggestion only): similarity of the normalised
   titles above a threshold, and a recording date or upload date within a few days of the
   date of the post.

A video already linked to another post is never suggested. When several strategies agree
the confidence goes up; when they point at different videos, all are shown.

### 2. Dates

The date of the post is the event date of a back-dated post, so the recording date of the
video (when set) is compared first, then the upload date. The tolerance is a few days
and is a filter.

### 3. Editor panel

For a video of the post that has no YouTube video, the panel shows the best candidates
(at most three) with their title, date, privacy, the reason ("address of the post found in
the description", "same title", "similar title and date") and a **Link this video**
button that goes through the confirmation of slice 014. The panel does not read the
channel until the author opens the suggestions (one catalogue read, see slice 015).

### 4. WP-CLI

`wp scatter-elsewhere suggest <post-id>` prints the candidates with their reason and
confidence.

### 5. Code structure

- `Matching/TitleNormalizer.php`: normal form of titles, pure.
- `Matching/VideoMatcher.php`: the strategies, the scores and the ranking, pure.
- `Matching/PostFacts.php` and `Matching/CatalogVideo.php`: the inputs of the matcher.
- Panel, REST route `GET /post/<id>/youtube/suggestions` and WP-CLI command.

## Non-goals

- Linking without confirmation (slice 017).
- Matching by the content of the video or its subtitles.
- Learning from the confirmations.

## Acceptance criteria

1. A video whose description holds the permalink of the post is the first candidate with
   high confidence, whatever the spelling of the address.
2. An exact title is high confidence when unique, and a suggestion when several videos
   share it.
3. A similar title is suggested only together with a close date.
4. A video linked to another post is never suggested.
5. The reasons are shown with the candidates.
6. Unit tests cover the normalisation, each strategy, the ranking and the exclusions with
   realistic titles and descriptions of the old script.

## Notes

- Posts published before the old script was used may have none of these traces: for them
  the manual and the list-based links remain.
