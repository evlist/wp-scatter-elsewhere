<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Link a video that is already on YouTube

## Summary

This slice lets the author tell the plugin that a video of a post **is already on
YouTube**: they give its address (or its id) in the editor panel, the plugin checks
that it is a video of the connected channel, shows what it found so that the author
can confirm, and records it. The post then behaves like a post whose video was
uploaded by the plugin, and a mistaken link can be removed.

## Context

Posts published before the plugin existed have videos that were uploaded by hand or
with the old script. Since slice 005 the `link` command of WP-CLI records such a video
without checking anything, from a bare YouTube id. This slice gives the same
possibility to the editor, with checks and with the forms of address that people
actually copy.

## User story

As an author, I want to link an old post to its video on YouTube so that the plugin
knows it is already there and can later update it, add its subtitles, its thumbnail or
its playlists, and show its YouTube link.

## Goals

- Link a video of a post to a YouTube video from the editor panel, by pasting its
  address or its id.
- Check before recording that the video exists and belongs to the connected channel,
  and show its title and privacy to confirm.
- Remove a link that is wrong, without touching YouTube.
- Prevent linking one YouTube video to two videos of the blog by accident.

## Functional requirements

### 1. Addresses

The following forms are accepted and reduced to the 11-character video id:
`https://www.youtube.com/watch?v=ID` (with other query parameters), `https://youtu.be/ID`,
`https://www.youtube.com/shorts/ID`, `https://www.youtube.com/embed/ID`,
`https://www.youtube.com/live/ID`, `https://m.youtube.com/watch?v=ID`, and the bare id.
Anything else is refused with a message.

### 2. Checking

Before anything is recorded, the video is read from YouTube (one `videos.list` call,
about 1 unit of quota):

- it must exist, otherwise the message says it was not found;
- it must belong to the connected channel (its channel id is compared with the one
  stored at connection time); when the channel id of the connection is unknown, the check
  is skipped and the author is told;
- its title, privacy and upload date are returned for the confirmation.

### 3. Confirmation and recording

The panel shows what it found ("Grenoble ⇾ Salers, private, uploaded on 5 October
2026") and a **Link this video** button. Recording stores the YouTube id, the privacy
read from YouTube and the time of the check (slice 010), with no upload job. Nothing is
modified on YouTube.

### 4. One YouTube video, one video of the blog

When the YouTube video is already linked to another video of the blog (same or other
post), the panel names that post and asks for a confirmation before moving the link. The
reverse lookup reads the post meta of slice 005 and is cached for a short time.

### 5. Removing a link

An **Unlink** action on a linked video removes the record only. It asks for a
confirmation, and never deletes or changes anything on YouTube. A video whose record
comes from a completed upload job (and has none of its own) is unlinked by recording
that the job no longer counts, so that unlinking really unlinks.

### 6. WP-CLI

- `wp scatter-elsewhere link <post-id> <video-id> <youtube-address> [--force] [--privacy=<privacy>]`
  accepts the same addresses and does the same checks; a video linked elsewhere is refused
  with the post it is linked to. `--force` asks YouTube nothing, records the link as given
  (with `--privacy` when known) and moves the link if it was elsewhere; it is meant for the
  cases where YouTube cannot be read or the author knows better.
- `wp scatter-elsewhere unlink <post-id> [<video-id>]`.

### 7. REST routes

`POST /post/<id>/youtube/link-preview` (address, `video_id`) returns the title, privacy,
upload date, whether the channel could be checked, and the post it is already linked to,
if any (with its title and edit address). `POST /post/<id>/youtube/link` (address,
`video_id`, `confirm_move`) records it; it answers 409 when the video is linked elsewhere
and the move was not confirmed. `POST /post/<id>/youtube/unlink` (`video_id`) removes the
link and answers 404 when there was none. Linking requires the post to be published and
the video to be one of those found in its page; unlinking does not read the page.

### 8. Code structure

- `YouTube/YouTubeAddress.php`: extracts the id from an address, pure.
- `Publication/LinkIndex.php`: tells where a YouTube video is linked, from the records of
  the posts and the finished uploads that predate them.
- `YouTube/VideoInspector.php`: gains a method that returns the title, privacy, upload
  date and channel of a video (it already reads the privacy).
- `Publication/LinkService.php`: preview, link, unlink and the reverse lookup, with
  injected collaborators.
- `Publication/PublicationStore.php`: removal of a record.
- Editor panel, REST controller and WP-CLI commands as above.

## Non-goals

- Changing anything on the YouTube video when linking (see the update slices).
- Linking a video of another channel.
- Searching the channel for the video (slices 015 and 016).
- Linking several YouTube videos to one video of the blog.

## Acceptance criteria

1. Every accepted form of address gives the right id, and other text is refused.
2. A video that does not exist, or that belongs to another channel, is refused with a
   readable message and nothing is recorded.
3. The preview shows the title, privacy and date, and names the post when the video is
   already linked elsewhere.
4. Linking records the privacy read from YouTube and the time of the check, and no job.
5. Unlinking removes the record, also when it came from a completed upload job, and
   leaves YouTube untouched.
6. The editor panel offers to link a video that has no YouTube video, and to unlink one
   that has.
7. Unit tests cover the address parsing, the checks and the link and unlink logic.

## Notes

- Status: implemented. The address parsing, the checks, the link, the move, the unlink
  (including the finished upload jobs, which are flagged so that they stop counting), the
  index and the inspector are unit-tested (350 PHP tests in total). The panel (look up,
  confirmation, move, unlink, error cases) was exercised with the jsdom harness, and the
  REST routes with stubs of the WordPress functions, including the conflict flow. The
  WP-CLI commands `link` and `unlink` have only been syntax-checked. Nothing has been run
  against a real WordPress or YouTube yet.

- The reverse lookup scans the posts that have the publication meta: fine for a blog
  with thousands of posts, to be reconsidered beyond that.
