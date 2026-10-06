<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Publication state

## Summary

This slice remembers, for each video of a post, the YouTube video it was uploaded
to. That prevents a second upload of the same video, lets a video that is already
on YouTube be linked to the post, and gives the blog a way to show a link to the
YouTube version.

## Context

After slice 004, an upload job is recorded, but nothing ties the YouTube video to
the post: running the upload again would create a duplicate on the channel and
spend a second upload of quota. The blog also stays the origin and keeps serving
the video, while offering the YouTube link to readers who prefer it.

## User story

As an administrator, I want the plugin to remember which YouTube video belongs to
which video of a post so that it never uploads it twice, and I want to show a link
to it on my blog.

## Goals

- Store the YouTube video id of each video of a post.
- Refuse to upload a video that already has one, unless explicitly forced.
- Link by hand a video that is already on YouTube (for example uploaded earlier
  with another tool).
- Expose the YouTube URL to themes and authors.
- Never advertise a private video to readers.

## Functional requirements

### 1. Storage

One post meta, `_wp_scatter_elsewhere_youtube`, holds an array keyed by the
detected video id of slice 003 (a hash of the path of the file inside the uploads
directory, hence stable):

```php
[
  'vd7427bfc1eac' => [
    'youtube_id'   => '9FzZpnEKL-s',
    'privacy'      => 'private',   // as requested; null when unknown
    'published_at' => 1791300000,  // Unix time of the upload or of the link
    'job_id'       => 'j8bdec2ec7286', // null for a manual link
  ],
]
```

The underscore keeps it out of the custom fields box. The value is written when an
upload job completes.

### 2. No second upload

- Creating an upload job is refused when the video already has a YouTube id, with a
  message that gives its address.
- A job that finished before this slice existed counts as well: the check also
  looks at completed jobs of the same post and video.
- The refusal can be overridden explicitly (`--force`), for example after the video
  was deleted on YouTube. A forced upload replaces the stored record when it
  completes.
- The plugin does not ask YouTube whether the video still exists.

### 3. Linking an existing video

`wp scatter-elsewhere link <post-id> <video-id> <youtube-id> [--privacy=<privacy>]`
records a YouTube video as the one of a detected video, without uploading anything.
The YouTube id must have the shape of a YouTube video id (11 characters among
letters, digits, `-` and `_`). It replaces an existing record.

### 4. Showing the YouTube link

- A function `wp_scatter_elsewhere_youtube_url( $post_id = null, $video_id = null, $include_private = false )`
  returns the YouTube URL of a video of the post (the current post by default; the
  first recorded video when no video id is given), or null.
- A shortcode `[scatter_elsewhere_youtube_link]` prints a link, with optional
  `post`, `video` and `text` attributes. The default text is translatable.
- **Private videos have no link**: the URL is not returned, and the shortcode prints
  nothing, unless `$include_private` is true (the shortcode has no such option).
  A video recorded without a known privacy is treated as shareable.
- A block for the editor is left for later: it needs a JavaScript build that the
  plugin does not have yet.

### 5. WP-CLI

- `wp scatter-elsewhere upload` gets a `--force` option.
- `wp scatter-elsewhere videos <post-id>` shows the YouTube id of the videos that
  have one.
- `wp scatter-elsewhere link ...` as above.

### 6. Code structure

- `Publication/Publication.php`: immutable record, with its YouTube address.
- `Publication/PublicationStore.php`: read and write through loader and saver
  closures.
- `Publication/PublicationLinks.php`: pure choice of the video to link and the
  private-video rule.
- `YouTube/Upload/UploadService.php`: refuses duplicates, records completed
  uploads, links videos.
- WordPress glue: `Publication/WordPressFactory.php`, `includes/functions.php`
  (the global template function), `Admin/Bootstrap.php` (shortcode), `Cli/Command.php`.

## Non-goals

- Updating a YouTube video after the post changed (see the ideas in
  `docs/slices/README.md`).
- Checking on YouTube that a recorded video still exists, or reading its real
  privacy status.
- An editor block, and automatic insertion of the link in the post content.
- Showing the state in the editor (later slices).

## Acceptance criteria

1. A completed upload records the YouTube id, privacy, time and job on the post.
2. Creating a job for a video that already has a YouTube id is refused with a
   message containing its address; `--force` allows it and the new id replaces the
   old one.
3. A job that completed before the record existed also blocks a duplicate.
4. `link` stores the record, rejects a malformed YouTube id and an unknown video
   or post.
5. The template function returns the URL of a recorded video of the post, chosen by
   video id when given, and returns null for a private video unless asked.
6. The shortcode prints an escaped link, and nothing for a private video or a post
   without a recorded video.
7. Unit tests cover the store, the link selection and the service changes without
   WordPress.
