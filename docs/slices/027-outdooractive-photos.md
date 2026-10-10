<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Outdooractive: prepare the photos of a tour

**Draft.** It depends on slices 025 and 026 and on facts about the photo upload of Outdooractive
that are to be confirmed (see *Open questions*).

## Summary

This slice **prepares the photos of a post to be added to its Outdooractive tour**: it picks the
images of the post, orders them, gives each its caption and credit, and offers them as one download
(and as a list to add one by one), ready for the upload form of the tour. As for the text (slice
026), the plugin does not connect to Outdooractive: the author adds the photos in the tour.

## Context

The post already holds the best photos of the hike, with captions in the media library and often the
original files with their GPS coordinates. Rebuilding that selection in the tour is a long, manual
step. Without an API that adds photos to a user's tour, the help for a trace that already exists is to
**make the selection and the files ready**. For a trace that does not exist yet, the ZIP import of
slice 028 can carry the photos with the trace.

## User story

As an author, I want the photos of my post gathered, ordered and captioned, so that I add them to my
Outdooractive tour in one go.

## Goals

- Select the images of a post (the gallery, the images of the content, the featured image) and let
  the author reorder, drop and caption them.
- Offer the original files, or a resized version within the limits of Outdooractive, in one archive.
- Keep what Outdooractive can use (the GPS position of a photo, the order, the captions) and say what
  it cannot.

## Functional requirements

### 1. The selection

In the Outdooractive panel, for a linked tour (or the post): a list of the images found in the content
of the post, in their order (blocks image, gallery, cover, classic content), with the thumbnail, the
file name, the caption (the caption of the block, else the one of the media library, else the alt
text), the credit (the author of the post or a custom field), and whether the file has a GPS position.
Each image can be ticked or not; the first is the featured image by default. The order can be changed.
Choices are kept per tour, like the edits of slice 026.

### 2. The files

- **Download the photos**: a ZIP of the ticked images, in the chosen order, named
  `01-<slug>.jpg`, `02-...` so that the order survives an upload by name. By default the **original**
  files (their metadata, including the GPS position, is kept); the option **Resized** produces JPEG
  files within the largest dimensions and the weight accepted by Outdooractive (to confirm), with the
  GPS position copied when the image editor allows it, or a warning when it is lost.
- **Captions**: a text file (`captions.txt`, or the captions in the panel with a Copy button) in the
  same order as the files, since the upload form probably takes the captions one photo at a time.
- The ZIP is built on request, in a temporary directory of the uploads folder, served once to a
  logged-in user with the capability, and removed after a short time. It is never public.

### 3. Warnings

- Images that are not JPEG or PNG, or too large for the limits, are listed with the reason.
- Images without a GPS position are listed: Outdooractive can place a photo on the map from it (to
  confirm), the others have to be placed by hand.
- Images hosted outside the media library (a remote address) are listed and left out, as the plugin
  only reads local files.

### 4. WP-CLI

`wp scatter-elsewhere oa-photos <post-id> [--resize] [--out=<directory>]` writes the files and the
captions in a directory (useful on a server the author reaches by `scp`).

### 5. Code structure

- `Outdooractive/ImageCollector` (reads the images of the content: blocks and classic HTML, resolves the
  local files; the parsing is pure over the HTML, the file lookup is injected),
  `PhotoSelection` (order and ticks, kept per tour), `PhotoPackager` (resize, naming, captions, ZIP;
  the image editor and the ZIP writer are injected), `ExifReader` for the GPS position (pure over the
  bytes, injected file reader).
- REST `post/<id>/outdooractive/photos` (list, selection) and a download route with a nonce;
  panel script; WP-CLI command.

## Non-goals

- Sending the photos to Outdooractive.
- Editing the images (crop, rotation, filters); the media library does that.
- Photos of the media library that are not in the post.
- Videos (the YouTube video of the post is a link in the text, slice 026).

## Acceptance criteria

1. The panel lists the images of the post in order with caption, credit and GPS indication, and the
   selection and order can be changed and are kept.
2. The ZIP contains the ticked images in order with names that sort in that order, the original files
   by default, and a captions file.
3. Images that cannot be used are listed with the reason, and the others are still packaged.
4. The ZIP is only served to an authorised user and is removed afterwards.
5. Unit tests cover the extraction of the images from block and classic content, the naming and the
   order, the caption fallback, and the GPS detection, without WordPress.

## Open questions

1. How photos are added to a recorded tour on Outdooractive: the number per upload, the accepted
   formats and weight, whether captions can be typed at the upload, and whether the GPS position of
   a photo places it on the map. All of this is to be confirmed on the real form.
2. Whether a recorded tour can receive photos from the web form at all, or only from the application
   (the same question as for the text).
3. The credit and licence of the photos (the author is the same person; a field is enough).
4. Where a ZIP of tens of megabytes is acceptable on the host (a limit on the number or the size of
   the images, with a clear message).
