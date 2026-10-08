<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Quota meter

## Summary

This slice shows **how much of the daily YouTube quota the plugin has used**: on the settings
page, in the YouTube panels of the editor (a warning when the limit is near) and in WP-CLI. The
batch operations (slices 018 and 020) use it to stop before the quota is exhausted.

## Context

The YouTube Data API has a daily quota (10,000 units by default per Google Cloud project, reset
at midnight Pacific Time). An upload costs about 1,600 units, so six uploads in a day can use it
all, and a bulk update or the reading of the channel spends more. **The API offers no call that
returns the quota used**: the figure exists only in the Google Cloud Console. The plugin can
therefore only **count its own requests** from a table of costs. The figure is an estimate and
says so: other tools using the same Cloud project (for example the Python scripts used before)
are not counted. A `quotaExceeded` answer from YouTube is the only certain information and
corrects the estimate.

## User story

As an administrator, I want to see how much quota I have used today and be warned before an
upload or a bulk operation fails because it is exhausted.

## Goals

- Count the quota units spent by the plugin, per day, per kind of request.
- Show the used and remaining units where they matter, as an estimate.
- Warn when the next action would not fit, in the panels and in the batch commands.
- Be exact about the day boundary and honest about what is not counted.

## Functional requirements

### 1. Counting

- Every request to the YouTube API goes through one HTTP wrapper; the meter records, after each
  request, its cost from a table (`quota-costs`): `videos.insert` 1600, `videos.update` 50,
  `videos.list` 1, `playlistItems.insert` / `delete` 50, `playlistItems.list` 1,
  `playlists.list` 1, `channels.list` 1, `captions.insert` 400, `captions.update` 450,
  `captions.delete` 50, `captions.list` 50, `thumbnails.set` 50. The table is data in one class, easy to
  correct when Google changes a cost.
- A request that fails before reaching the API (transport error) costs nothing; one answered with
  an error costs what YouTube charges (1 unit minimum; invalid requests are charged too).
- The day is the **Pacific Time** day (`America/Los_Angeles`, with the daylight saving time), the
  one YouTube resets on. The counter is an option (autoload disabled) keyed by that date, with the
  breakdown per method; older days are dropped (a week is kept for the history).
- The daily limit is a setting (default 10,000): the owner of a Cloud project may have obtained
  more.
- When YouTube answers `quotaExceeded`, the meter marks the day **exhausted** until the next
  reset, whatever the count said (the count is raised to the limit).

### 2. Settings page

A **Quota** section in the YouTube settings: used / limit with a bar, the breakdown by kind of
request for today, the last seven days, the time of the next reset (in the site time zone), the
limit field and a note: "Estimated from the requests of this plugin. Other tools using the same
Google Cloud project are not counted. The exact figure is in the Google Cloud Console" with a
link to the quota page of the project. A **Reset the counter** button for the case where the
estimate drifted.

### 3. Warnings in the panels

- The state returned to the editor panels (REST) includes `quota: {used, limit, remaining,
  level, resets_at}` where `level` is `ok`, `low` (80 % used, or fewer units left than one upload
  costs when an upload is possible) or `exhausted`.
- The panel shows a notice under the YouTube actions: for `low`, "About N units of the daily
  YouTube quota are left; a new upload needs about 1,600"; for `exhausted`, "The daily YouTube
  quota is exhausted. It is reset at HH:MM." Nothing is shown at the `ok` level.
- The **Publish to YouTube** button is not disabled (the estimate may be wrong), but its
  confirmation says that the quota is probably insufficient. After a `quotaExceeded` answer the
  upload job waits until the reset instead of failing (a job state already planned in slice 004's
  retry policy, made explicit here: status "waiting for the quota", with the reset time).
- The same notice appears in the pages of slices 019 and 020.

### 4. Batch operations

- `--quota-limit` of slice 018 defaults to the **remaining** quota; the report states the
  estimate against the remaining units, and the run stops cleanly when the next video would not fit.
- The background job of slice 020 pauses until the reset when the meter says exhausted.

### 5. WP-CLI

`wp scatter-elsewhere quota [--reset]` prints the used and remaining units, the breakdown and the
time of the reset.

### 6. Code structure

- `Quota/CostTable.php` (method → units), `Quota/QuotaMeter.php` (record, today, remaining,
  exhausted, level; injected clock, storage and time zone, pure), `Quota/QuotaDay.php` (the
  Pacific day boundary).
- `YouTube/MeteredHttp.php`: a decorator of the existing HTTP closure that identifies the method from
  the URL and HTTP verb and records the cost; wired once in `YouTube\WordPressFactory::uploadHttp()`
  so that every client is counted without changes.
- Settings section, REST state field, panel notice, CLI command.

## Non-goals

- Reading the real quota from Google (not possible with the Data API; the Cloud Monitoring API
  could, but it needs another scope, another API and another credential).
- Requesting a quota extension.
- Counting what other applications spend.

## Acceptance criteria

1. Each request is counted with the cost of its kind, and a failed transport costs nothing.
2. The counter changes day at midnight Pacific Time, also around the daylight saving changes.
3. `quotaExceeded` marks the day exhausted and the upload jobs wait for the reset.
4. The settings page shows used, limit, breakdown, next reset and the caveat.
5. The panel warns at `low` and `exhausted` and says nothing below.
6. The batch commands default their limit to the remaining quota.
7. Unit tests cover the cost table, the day boundary (including daylight saving), the levels and
   the exhaustion, without WordPress.

## Open questions

1. The costs of the table are the documented ones at the time of writing; they should be checked
   against the Cloud Console after the first real days and corrected if they differ.
