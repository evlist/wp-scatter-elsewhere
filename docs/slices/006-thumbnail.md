<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Thumbnail

## Summary

This slice sets the **featured image of the post as the thumbnail** of its YouTube
video. The image is prepared (cropped to 16:9, scaled down, compressed under the
size limit of YouTube), then sent after an upload or on demand for a video that is
already on YouTube.

## Context

In the existing workflow, the featured image of the post is set as the thumbnail by
hand in YouTube Studio. The featured image of a blog is usually a large photograph,
often not 16:9 and often far above the 2 MB limit of YouTube for thumbnails, so it
cannot be sent as it is.

## User story

As an administrator, I want my videos to get the featured image of their post as
thumbnail without opening YouTube Studio.

## Goals

- Send the featured image of the post as the thumbnail of its video.
- Make the image acceptable to YouTube: 16:9, 1280 x 720 at most, JPEG, 2 MB at most.
- Never fail an upload because of its thumbnail.
- Be able to send it again afterwards (the image changed, or the video was already
  on YouTube).

## Functional requirements

### 1. Source

The original file of the featured image of the post (not a generated size), when
the post has one. A post without featured image has no thumbnail to send and the
step is skipped without a message.

### 2. Preparation

- The image is cropped around its centre to 16:9, then scaled down to 1280 x 720
  when it is larger. An image smaller than that is not scaled up.
- It is saved as JPEG. The quality starts at 90 and goes down by steps (80, 70, 60,
  50) until the file is at most 2 MiB. When even the lowest quality is too large,
  the thumbnail is not sent and the reason is reported.
- The computation of the crop box and the choice of the quality are pure and
  unit-tested; the image functions of WordPress only do the cropping, the scaling
  and the saving.
- The temporary files are always removed.

### 3. Sending

`thumbnails.set` with the video id and the JPEG data. YouTube only allows custom
thumbnails for channels whose account has been verified: when it refuses with
"forbidden", the message says so.

### 4. Setting

**Send the featured image as the thumbnail of the video**, enabled by default.

### 5. After an upload

- The path of the featured image is stored in the upload job when it is created.
- When the upload completes, the thumbnail is sent. This is **best effort**, like the
  subtitles: the upload stays `done` and a problem is stored in the warning of the
  job, shown by `wp scatter-elsewhere jobs`. Warnings of several steps are joined.

### 6. Afterwards

`wp scatter-elsewhere thumbnail <post-id> [<video-id>]` prepares the current featured
image of the post and sends it to the YouTube video recorded for that video
(slice 005).

### 7. Code structure

Under `plugin/includes/WP_Scatter_Elsewhere/`:

- `Thumbnails/ThumbnailGeometry.php`: centre crop to 16:9 and target size, pure.
- `Thumbnails/ThumbnailPreparer.php`: tries the qualities until the image fits, pure.
- `Thumbnails/ThumbnailService.php`: prepares then sends.
- `YouTube/ThumbnailClient.php`: the `thumbnails.set` call.
- `YouTube/ThumbnailException.php`.
- Metadata: the post data and the video metadata carry the path of the featured image.
- `YouTube/Upload/*`: the job carries it, the service sends the thumbnail after an
  upload, together with the subtitles.
- WordPress glue: the image editor of WordPress (`Thumbnails/WordPressFactory.php`),
  `Metadata/WordPressFactory.php`, `Admin/SettingsPage.php`, `Cli/Command.php`.

## Non-goals

- Choosing another image than the featured image, text overlays, or several
  thumbnails (YouTube has no A/B test through this API).
- Letterboxing instead of cropping.
- Checking that the channel is verified before the call.

## Acceptance criteria

1. The crop box is the largest centred 16:9 rectangle of the image, and the target
   size is 1280 x 720 at most, never larger than the source.
2. The preparer returns the first rendering that fits in 2 MiB, tries the qualities
   in decreasing order, and reports a failure when the renderer fails or nothing fits.
3. The thumbnail is sent with the right video id, content type and data.
4. A completed upload sends the stored image, stays `done` and records a warning when
   the thumbnail or the subtitles could not be sent.
5. Without a featured image, or with the setting off, nothing is prepared or sent.
6. A "forbidden" answer produces the hint about verified channels.
7. Unit tests cover all this without WordPress.

## Notes

- Status: implemented. The geometry, the preparer, the client, the service and the
  upload integration are unit-tested with fake HTTP closures and renderers (256 tests
  in total). The image rendering of `Thumbnails/WordPressFactory` was exercised with a
  stand-in for the image editor of WordPress based on GD: a 3000 x 2000 photograph
  gives a 1280 x 720 JPEG, a small square is cropped without being scaled up, and no
  temporary file is left. The `thumbnail` command has only been syntax-checked.
  Nothing has been run against a real WordPress or YouTube yet.

- The limits (2 MB, 16:9 and 1280 x 720 recommended, JPEG, PNG, GIF or BMP accepted,
  verified channels only) and the shape of the request come from memory of the
  YouTube documentation and must be confirmed with a real call, to be done on the
  private test video.
