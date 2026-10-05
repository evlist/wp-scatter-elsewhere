<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Video upload

## Summary

This slice uploads a detected video to YouTube as a background job, with the
title and description composed by slice 002 and a **privacy status that defaults
to `private`**, so the feature can be tested without anything showing on the
channel. It delivers the job queue, the resumable upload and a command-line entry
point; the editor interface comes in later slices.

## Context

A video file can be large and the upload long, so it cannot run inside a web
request. YouTube offers a resumable upload protocol (one request opens an upload
session, then the file is sent in chunks that can be resumed after an
interruption), which maps well onto WP-Cron: each run sends some chunks within a
time budget, then schedules itself again until the file is complete.

Slices 001 (access tokens), 002 (title and description) and 003 (detected video
with its local file) provide everything the upload needs.

## User story

As an administrator, I want to upload a video of a post to YouTube from
WordPress, safely and in the background, and to try it without making it public.

## Goals

- Upload a detected local video with a title, a description and a privacy status.
- Survive interruptions, network errors, quota errors and PHP time limits.
- Show the state of each upload.
- Default to `private`, so tests never publish anything on the channel.
- Keep the logic independent of WordPress and unit-tested; the WordPress and
  WP-CLI parts stay thin.

## Functional requirements

### 1. Privacy

- Allowed values: `private`, `unlisted`, `public`.
- A setting **Default privacy** on the settings page, default `private`.
- An explicit privacy can be given for a given upload; otherwise the setting is
  used. The later editor slice asks it at publication time.
- To the best of our knowledge, videos uploaded through the API by projects that
  have not passed Google's API compliance audit are forced to `private` whatever
  the requested value. A project in that situation can only test with private
  videos, and needs the audit to publish publicly. This is documented in the
  README.
- Removing a test video is done in YouTube Studio (the plugin has no deletion
  and does not request the permission for it).

### 2. Jobs

A job is created for a post and a detected video. It holds: the post and
detected-video ids, the local file path, size and MIME type, title, description,
privacy, the YouTube category (fixed to `22`, as in the existing script), a status
and the progress data.

Statuses: `queued`, `uploading`, `retry`, `done`, `failed`.

- A job in `queued`, `uploading` or `retry` is *active*.
- Creating a job is refused when the video is not uploadable (slice 003), when the
  file is empty, when the privacy is invalid, or when an active job already exists
  for the same post and video.
- Jobs are kept in a single non-autoloaded option. Only the 100 most recent jobs
  are kept; active jobs are never dropped.
- Preventing a second upload of a video that is already on YouTube, and storing the
  YouTube id on the post, belong to slice 005.

### 3. Resumable upload

- The upload session is opened with the title, description, category, privacy and
  `selfDeclaredMadeForKids = false` (the existing script's default). Its URL is
  stored in the job.
- The file is sent in chunks of 8 MiB, a multiple of 256 KiB as the protocol
  requires. The size of the chunks is a constructor parameter.
- After an interruption, the position is read back from YouTube with a status
  query instead of being trusted from local state.
- A session that YouTube no longer knows is restarted from the beginning.
- Access tokens come from the provider of slice 001, which is asked for each
  request.

### 4. Time budget and scheduling

- Each run uploads chunks until the file is complete or its time budget (20 s for
  WP-Cron) is exhausted; an unfinished job schedules its next run immediately.
- A job is locked while it runs, so two concurrent cron runs never send the same
  file. The lock expires after the budget plus five minutes.
- WP-Cron needs visits to run. Sites with little traffic should call
  `wp-cron.php` from a system cron; the README says so.

### 5. Errors

- Network errors, HTTP 5xx and 429, and quota errors (`quotaExceeded`,
  `dailyLimitExceeded`, `rateLimitExceeded`, `userRateLimitExceeded`) put the
  job in `retry` with an exponential delay (30 s doubling, at most one hour).
  After 12 consecutive failures the job becomes `failed`. A chunk that succeeds
  resets the counter.
- Other client errors (such as 400 or 403 for another reason), an unreadable or
  vanished file, a missing connection or a refused authorisation make the job
  `failed` with a readable message. A job whose authorisation was revoked says
  that the administrator must connect again.
- A failed job can be retried; it then restarts as `queued` with a fresh counter.
- Every error message is translatable and stored in the job.

### 6. Command line

WP-CLI is the first entry point (it is a development and administration tool,
not something product logic depends on at runtime):

- `wp scatter-elsewhere videos <post-id>`: list the videos detected in the public
  page of the post, with their id, size, subtitles and whether they can be
  uploaded.
- `wp scatter-elsewhere upload <post-id> [<video-id>] [--privacy=<privacy>] [--now]`:
  compose the metadata from the post and create a job. The video id may be
  omitted when the post has a single uploadable video. With `--now` the job runs in
  the terminal with a progress report instead of waiting for WP-Cron.
- `wp scatter-elsewhere jobs`: list the jobs and their state.
- `wp scatter-elsewhere retry <job-id>`.

### 7. Code structure

Under `plugin/includes/WP_Scatter_Elsewhere/`:

- `YouTube/Upload/UploadJob.php`: immutable job with `toArray()`/`fromArray()`.
- `YouTube/Upload/UploadJobStore.php`: persistence through loader and saver closures.
- `YouTube/Upload/ResumableUploader.php`: the protocol, with injected HTTP, file
  reader, clock and token provider.
- `YouTube/Upload/UploadService.php`: creating, running, scheduling, locking and
  retrying jobs, with injected scheduler, clock and id generator.
- `Settings/UploadSettings.php`: the default privacy.
- WordPress glue: `YouTube/WordPressFactory`, `Admin/SettingsPage`, `Admin/Bootstrap`
  and `Cli/Command.php`.

## Non-goals

- Thumbnail, playlists, language, license, recording date, subtitles and tags
  (slices 006 to 009 and 013).
- Editor panel and button (slices 010 and 011).
- Preventing re-uploads and storing the YouTube id (slice 005).
- Deleting videos on YouTube.
- Several videos in parallel; jobs run one run at a time.

## Acceptance criteria

1. A job is created with `private` unless another valid privacy is given or the
   setting says otherwise.
2. The upload session request carries the title, description, category, privacy and
   the file size and MIME type; the chunks carry correct `Content-Range` headers.
3. A multi-chunk file is uploaded across several runs when the budget is
   exhausted, resuming at the position confirmed by YouTube.
4. An interrupted upload (network error, 5xx) goes to `retry` with a growing
   delay, then synchronises its position and continues.
5. A quota error goes to `retry`; a non-quota 403 and a 400 go to `failed`.
6. An unknown session restarts the upload.
7. A revoked authorisation makes the job `failed` with a message asking to connect
   again.
8. A locked job is not run twice; a retry delay that has not elapsed postpones the run.
9. Duplicate active jobs, non-uploadable videos and invalid privacy values are
   refused.
10. The WP-CLI commands list videos, create jobs, run them with `--now` and list
    them.
11. Unit tests cover the uploader, the job store, the service and the settings
    with fake HTTP closures and an in-memory file reader.

## Notes

- Status: implemented. The uploader, the job store, the service and the settings
  are unit-tested with an in-memory fake of the YouTube resumable upload endpoint.
  The WordPress glue (`YouTube/WordPressFactory`, `Admin/SettingsPage`,
  `Admin/Bootstrap`) was run with stubs of the WordPress functions and fake HTTP,
  uploading a 21 MB file in three chunks. `Cli/Command.php` has only been
  syntax-checked. None of it has been run against a real WordPress or YouTube yet.
- The protocol details (endpoint, headers, 308 handling, quota reasons) come from
  memory of the YouTube documentation and still have to be confirmed by a first
  real upload, which should be done as a private video.

- Uploading is the most expensive YouTube API call. With the default quota
  (10,000 units per day, to the best of our knowledge about 1,600 per upload),
  only a handful of uploads fit in a day, including tests; quota errors are
  therefore treated as normal and retried.
- The chunk size can be lowered for slow connections, since a chunk is the unit
  that must fit in PHP's time limit.
- Jobs are stored in one option, so two jobs finishing at the same instant could
  overwrite each other's update. Uploads are sequential in practice; a dedicated
  table is out of scope until it matters.
