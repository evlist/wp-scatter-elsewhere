<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Editor panel

## Summary

This slice adds a **YouTube panel to the block editor**: after the post is published it
offers to publish the video on YouTube (choosing the video, the privacy and the
license), and it shows the state of the upload. The same panel is also available in
the document sidebar of a published post, so the upload can be started later or
retried (the "later" and "retry" part that was planned as slice 011).

## Context

Publishing is done from WP-CLI so far. The post must be published before the upload:
the description contains its address and the videos are found in its public page. The
question is therefore asked right after publication, in the panel that the editor
shows once a post is published.

## User story

As an author, I want to be offered to publish the video of my post on YouTube right
after publishing the post, and to follow the upload, without leaving the editor.

## Goals

- A panel shown after publication, and in the sidebar of a published post.
- Choose the video when the post has several, the privacy and the license.
- Start the upload in the background and show its progress, the YouTube link when it
  is done, and the errors and warnings.
- Retry a failed upload.
- Never start anything without a click.

## Functional requirements

### 1. Access

- The panel and its REST routes require the `manage_options` capability (the YouTube
  channel belongs to the administrator who connected it) and the right to edit the post.
  The capability can be changed with the filter `wp_scatter_elsewhere_capability`.
- The panel only exists for the post types of the editor that are public, and says what
  is missing when it cannot work: the plugin is not connected to YouTube (with a link to
  the settings page), the post is not published, the page of the post cannot be read.

### 2. REST routes

Namespace `wp-scatter-elsewhere/v1`:

- `GET /post/<id>/youtube`: detects the videos of the published post (slice 003) and
  returns, for each of them: the file name and size, whether it can be uploaded and why
  not, its subtitle tracks, the YouTube video if it already has one (slice 005), and its
  upload job if there is one. It also returns the default privacy and license.
- `GET /post/<id>/youtube/jobs`: the same job and YouTube information without detecting
  the videos again, for the polling.
- `POST /post/<id>/youtube/upload` with `video_id`, `privacy` and `license`: validates the
  request, builds the metadata of the post (slices 002, 006, 007 and 008) and creates the
  upload job. Refused when the video cannot be uploaded, already has a YouTube video or
  an upload in progress, or when the privacy or license is invalid.
- `POST /job/<id>/retry`: restarts a failed upload (slice 004).

Errors are returned as REST errors with a readable message.

### 3. The panel

- A published post with videos: for each video, its name and size, its subtitle tracks,
  and its state:
  - nothing yet: a **Publish to YouTube** button, with a choice of privacy and license
    (defaults from the settings, so private unless changed);
  - upload in progress: a progress bar and the state (queued, uploading, waiting to retry);
  - done: the YouTube link, with the privacy, and the warnings of the job if any;
  - failed: the error and a **Retry** button.
- Polling every few seconds while an upload is in progress, and only then.
- A video that already has a YouTube video shows its link and no button.
- The strings are translatable (`@wordpress/i18n`), as for any script of the plugin.

### 4. Code structure

Under `plugin/includes/WP_Scatter_Elsewhere/`:

- `Editor/PostYouTubeState.php`: turns the detected videos, publications and jobs into
  the data sent to the panel; pure and unit-tested.
- `Editor/UploadRequestValidator.php`: validates an upload request; pure and unit-tested.
- `Rest/YouTubeController.php`: the routes and their permissions (WordPress glue).
- `Admin/EditorPanel.php`: loads the script in the editor and its translations.
- `plugin/assets/js/editor-youtube-panel.js`: the panel, without build step, in the
  style of the other script of the family of plugins.

## Non-goals

- Reviewing or editing the title, description and other metadata before the upload
  (an idea to schedule).
- Updating a video when the post changes (an idea to schedule).
- Starting the upload automatically on publication.
- Choosing several videos at once (one upload per click).
- Other post types than the public ones of the block editor, and the classic editor.

## Acceptance criteria

1. The state sent to the panel describes each video with its file, subtitles, YouTube
   video and latest upload job, and the defaults of the settings.
2. An upload request with an unknown video, an invalid privacy or an invalid license
   is refused with a readable message; empty values take the defaults.
3. The routes refuse users who may not manage the plugin or edit the post.
4. A post that is not published, a plugin that is not connected and a page that cannot
   be read give an explanation instead of an error.
5. Clicking the button creates an upload job and the panel shows its progress, then the
   YouTube link.
6. A failed job can be retried from the panel.
7. Unit tests cover the presenter and the validator without WordPress.

## Notes

- The two panels (after publication and in the sidebar) can be on screen at the same
  time: they share one state, one loading and one polling, so that an action in one is
  seen in the other.
- Status: implemented. The presenter, the validator and `UploadService::jobsForPost` are
  unit-tested (303 PHP tests in total). The script was exercised with a throw-away harness
  (jsdom and React, with stand-ins for the `wp` packages, not kept in the repository):
  display of a video, upload with the chosen privacy and license, progress and link,
  retry, the error and explanation states, and the sharing between the two panels. The
  REST controller was run with stubs of the WordPress functions on the real page excerpt
  of `tests/fixtures`: detection, validation, creation of the job, refusal of a second
  upload and of an unpublished post, retry, permissions. Nothing was run in a real block
  editor, WordPress or YouTube yet; in particular the slots of the editor
  (`PluginPostPublishPanel`, `PluginDocumentSettingPanel`) were not checked against a real
  WordPress.
