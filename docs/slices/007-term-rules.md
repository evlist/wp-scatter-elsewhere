<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Term rules: playlists and keywords

## Summary

This slice lets the terms of a post (categories, tags, any taxonomy) decide in which
YouTube **playlists** its video is put and which **keywords** (YouTube tags) it gets.
The rules are an editable table in the settings: one row per term, with an optional
playlist and an optional keyword. A row can therefore produce both, or only one.

## Context

In the existing workflow the playlists correspond more or less to some categories and
are chosen by hand in YouTube Studio. The keywords were not used, and the author wants
the same mechanism for them, generalised.

## User story

As an administrator, I want the categories and tags of a post to put its video in the
right playlists and give it the right keywords, without opening YouTube Studio.

## Goals

- A table of rules in the settings: term, playlist (optional), keyword (optional).
- Apply the rules to a new upload and to a video that is already on YouTube.
- Choose the playlists from a list read from YouTube instead of typing identifiers.
- Stay simple: no conditions, no priorities, no inheritance.

## Functional requirements

### 1. Rules

A rule has:

- a **taxonomy** (category, tag, or any taxonomy of the posts);
- a **term** of that taxonomy, identified by its slug and shown by its name;
- an optional **playlist** (a YouTube playlist id);
- an optional **keyword** (free text, typed by hand);
- for a hierarchical taxonomy (categories), an **include sub-terms** checkbox, off by
  default.

At least one of the playlist and the keyword is required. There is one rule per term:
a term cannot be listed twice. A term that is not in the table is ignored.

By default a rule matches a post that has the term itself: the children of a
hierarchical term do not inherit the rule of their parent. With **include sub-terms**
checked, the rule also matches the posts that have any descendant of the term.

### 2. Applying the rules

For a post, every rule whose term the post has applies:

- the playlists are collected without duplicates;
- the keywords are collected without duplicates (case-insensitive) in the order of the
  table.

YouTube limits the keywords of a video (to the best of our knowledge 500 characters in
total, and a few special characters are forbidden). The keywords that do not fit are
left out and reported; angle brackets are removed.

### 3. New uploads

- The keywords are sent with the video (`snippet.tags`).
- The playlists are stored in the upload job, and the video is added to each of them
  once it is uploaded. Like the subtitles and the thumbnail, this is best effort: a
  problem is a warning of the job and never makes the upload fail.

### 4. Videos already on YouTube

- `wp scatter-elsewhere apply-metadata <post-id> --fields=keywords` adds the missing
  keywords to the video, keeping the existing ones (the video is read first, as for the
  other properties).
- `wp scatter-elsewhere playlists-add <post-id> [<video-id>]` adds the video to the
  playlists of the rules, skipping the playlists that already contain it.
- `wp scatter-elsewhere playlists` lists the playlists of the channel (id and title).

### 5. Settings page

A table with one row per rule: term (chosen among the terms of the public taxonomies),
playlist, keyword, include sub-terms, and a "remove" checkbox, plus a few empty rows to
add rules (empty rows are ignored when saving). The playlist is a list showing the
titles of the playlists of the channel, read from YouTube (kept one hour, and read again
each time the rules are saved); a playlist that is not in the list keeps its ID as its
label. When the list cannot be read, the field becomes a text field where the ID can be
typed.

The rules are validated when they are saved: unknown taxonomy or term, empty rule,
term listed twice, malformed playlist id.

### 6. Storage

One non-autoloaded option `wp_scatter_elsewhere_term_rules`:

```php
[
  [ 'taxonomy' => 'category', 'term' => 'vanlife', 'playlist_id' => 'PLxxxx', 'keyword' => 'vanlife', 'include_children' => true ],
  [ 'taxonomy' => 'post_tag', 'term' => 'salers',  'playlist_id' => '',       'keyword' => 'Salers',  'include_children' => false ],
]
```

### 7. Code structure

Under `plugin/includes/WP_Scatter_Elsewhere/`:

- `Rules/TermRule.php`, `Rules/TermRules.php`: the rules and their validation, pure.
- `Rules/RuleMatcher.php`: the playlists and keywords of a post, pure.
- `Settings/TermRuleSettings.php`: storage through loader and saver closures.
- `YouTube/PlaylistClient.php`: list the playlists of the channel, check whether a
  playlist contains a video, add a video to a playlist.
- `Playlists/PlaylistService.php`: adds a video to a list of playlists, skipping the
  ones that already have it.
- Metadata: the post data carries its terms (it already does) and the video metadata
  carries the keywords and the playlists.
- `YouTube/VideoUpdater.php`: a `tags` field that merges keywords with the existing ones.
- `YouTube/Upload/*`: the job carries the keywords and the playlists.
- WordPress glue: `Rules/WordPressFactory.php`, `Admin/SettingsPage.php`, `Cli/Command.php`.

## Non-goals

- Creating playlists, removing a video from a playlist, ordering inside a playlist.
- Several playlists or keywords in one row (use several terms, or several rows of
  different terms).
- Conditions, priorities, exclusions.
- Inheritance of a rule by default (it is opt-in per rule, with the checkbox).
- A keyword taken automatically from the name of the term (the keyword is typed).
- Removing the keywords or playlists that no longer match after a post changed, and
  updating the video automatically when a post changes: both stay manual.

## Acceptance criteria

1. A row can have a playlist, a keyword, or both, and is refused when it has neither.
2. A term cannot be listed twice, and an unknown taxonomy or term is refused.
3. The playlists and keywords of a post are those of the rules whose term the post has,
   without duplicates. A rule with **include sub-terms** also matches the posts that
   only have a descendant of its term; without it, it does not.
4. A new upload sends the keywords with the video and adds it to the playlists once
   uploaded, leaving it `done` with a warning when a playlist could not be reached.
5. Keywords that do not fit the YouTube limit are left out and reported.
6. Updating a video adds the missing keywords and keeps the existing ones.
7. Adding a video to the playlists skips those that already contain it.
8. Unit tests cover the rules, the matcher, the clients and the services without
   WordPress.

## Notes

- Status: implemented. The rules, their validation, the matching (with and without
  sub-terms), the keyword limit, the playlist and keyword calls, the upload and the
  update are unit-tested with fake HTTP closures (289 tests in total). The settings
  page (rules table, saving, errors) and an upload with keywords and a playlist were run
  with stubs of the WordPress functions. The `playlists`, `playlists-add` and
  `apply-metadata --fields=keywords` commands have only been syntax-checked, and the
  retrieval of the terms of a post with their ancestors (`Metadata/WordPressFactory`) was
  not run. Nothing has been run against a real WordPress or YouTube yet.

- The shape of the playlist calls (`playlists.list`, `playlistItems.list`,
  `playlistItems.insert`), the limits on keywords and the quota costs come from memory
  of the YouTube documentation and must be confirmed with real calls, to be done on the
  private test video.
