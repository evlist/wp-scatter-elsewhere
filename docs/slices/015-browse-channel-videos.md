<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Browse the videos of the channel

## Summary

This slice lets the plugin **list the videos of the connected channel** and lets the
author **pick one in a list** instead of pasting an address. The list is read from the
uploads of the channel, kept for a while, searchable, and shows for each video its
title, date, privacy and whether it is already linked to a post of the blog.

## Context

Slice 014 links a video from its address, which the author has to look up in YouTube
Studio. Picking it from the list of the channel is quicker, and is also the base of the
automatic suggestions (slice 016) and of the bulk linking (slice 017).

## User story

As an author, I want to choose the video of my old post in the list of my YouTube
videos, with a search box, without leaving the editor.

## Goals

- Read the uploads of the channel, private ones included, at a low quota cost.
- Show them in a list that can be searched, in the editor panel and in WP-CLI.
- Show which ones are already linked to the blog.
- Keep the list for a short time and refresh it on request.

## Functional requirements

### 1. Reading the channel

- The uploads playlist of the channel is read (`channels.list` for its id, then
  `playlistItems.list`, 50 videos per page: about 1 unit of quota per 50 videos). The
  details of the videos (privacy, description, recording date) are read by batches of 50
  (`videos.list`, 1 unit per batch).
- The listing is limited to the first 500 videos by default (a safety limit, which is
  configurable by a filter), most recent first.
- The result is kept in a transient (one hour), with a **Refresh the list** action.

### 2. What is shown

For each video: title, upload date, privacy, thumbnail address (shown small when the
interface has room), and the post it is linked to, if any (title and link). Videos that
are already linked to another post are shown but marked, and choosing one goes through
the confirmation of slice 014.

### 3. Editor panel

On a video of the post that has no YouTube video, **Choose from my channel** opens a
list with a search box (title contains) and a refresh action. Choosing a video shows the
same confirmation as slice 014.

### 4. WP-CLI

`wp scatter-elsewhere channel-videos [--search=<text>] [--unlinked] [--limit=<n>] [--refresh]`
lists the videos with their id, title, date, privacy and linked post.

### 5. Code structure

- `YouTube/ChannelCatalog.php`: reads and caches the videos of the channel; pure
  parsing and paging, injected HTTP and cache.
- `Publication/LinkIndex.php`: YouTube id to post and video, from the publication meta
  (shared with slice 014).
- REST route `GET /youtube/channel-videos` (search, refresh), editor panel list, WP-CLI
  command.

## Non-goals

- Playlists, comments, analytics or anything that is not the list of videos.
- Videos of other channels.
- More than the safety limit of videos.

## Acceptance criteria

1. The uploads of the channel are listed across pages with their privacy, most recent
   first, within the limit.
2. The list is kept for an hour and read again on request.
3. The search narrows the list by title, ignoring case and accents.
4. Videos already linked are marked with their post.
5. Choosing a video in the panel leads to the confirmation of slice 014.
6. Quota use is reported in the documentation and stays at a few units per refresh.
7. Unit tests cover the paging, the caching and the search without WordPress.

## Notes

- To the best of our knowledge the uploads playlist of the owner includes the private
  and unlisted videos; this must be confirmed with the real channel.
