<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Administration page: update the videos

## Summary

This slice puts the **bulk update** of slice 018 in the administration, under **Tools**: choose
which videos and which parameters, see what would change, and apply in the background with a
progress display. It also keeps a log of what was changed.

## Context

An update of many videos takes minutes (one or a few requests to YouTube per video, plus a
thumbnail or subtitles when selected), more than a web request can last, and it spends quota. It
must therefore run like the uploads: as a background job, in short steps, that can be followed,
stopped and resumed, while the page only shows the plan and the progress.

## User story

As an administrator, I want to apply a changed default or rule to the videos already on YouTube
from a page, to review the differences before, and to follow the work.

## Goals

- The selection, the fields and the add/sync modes of slice 018, as a form.
- A preview of the differences before anything is sent.
- A background run in steps within the time budget of WP-Cron, with progress, stop and resume.
- A log of the changes, with the previous value of each, so that a mistake can be corrected.

## Functional requirements

### 1. The form

**Tools → Scatter Elsewhere – Update videos**:

- **Which videos**: all linked videos, since / until a date, only the posts with a term (a
  taxonomy and term selector), a list of post IDs, a maximum number.
- **What to update**: one checkbox per field of slice 018 (title, description, language,
  license, recording date, category, options, keywords, playlists, thumbnail, subtitles). Nothing
  is ticked by default. Title and description carry a warning ("rewrites the text of every
  video"). Privacy is a separate, clearly marked choice with a value to apply and a
  confirmation.
- **Playlists** and **Keywords**: a choice "Only add" (default) / "Add and remove those that no
  longer apply". The help states what is managed (the playlists and keywords named by the
  rules) and what is never touched.

### 2. The preview

**Preview** reads the current state of the videos (1 quota unit each, in steps with a progress
bar, like the scan of slice 019) and shows a table: video, field, current value, new value,
action. Videos without any difference are counted, not listed. The preview shows the estimated
quota of the run and the quota already spent today when known (from the meter of slice 021, to warn before the daily limit is reached).

### 3. The run

**Apply** creates a *batch job* with the preview as its plan and schedules it. The job:

- is processed by WP-Cron in steps of about 20 seconds (as the uploads), one video at a time,
  with a lock, backoff on quota or transient errors, and a pause when the meter of slice 021 says the daily quota is
  exhausted (it resumes the next day, with a message);
- is shown by the page with a progress bar, counts (updated, unchanged, errors), the last
  messages, **Stop** and **Resume**;
- records for each change the **previous value**, so that the log can be exported (CSV) and a
  value restored by hand or by a later run;
- never starts two jobs at once; a new one waits for the previous to finish or be stopped.

### 4. Reports

Finished jobs stay listed (the last 20) with the date, the filters, the fields, the counts and
the log. A job started from the command line (slice 018) is not recorded here.

### 5. Code structure

- `Update/BatchPlan.php`, `Update/BatchJob.php`, `Update/BatchJobStore.php`: the plan, the job and
  its storage (options, autoload disabled), modelled on `UploadJob` and `UploadJobStore`.
- `Update/BatchRunner.php`: one step (budget, lock, backoff), using the `BatchUpdater` of slice 018;
  pure with injected closures, unit tested.
- `Admin/UpdateToolsPage.php`, `Rest/ToolsController.php` (routes `tools/update-preview`,
  `tools/update-start`, `tools/update-status`, `tools/update-stop`), `assets/js/update-tools.js`.

## Non-goals

- Reverting automatically (the log gives the previous values; a restore function can follow).
- Scheduling recurring updates.
- Editing individual values in the preview.

## Acceptance criteria

1. Nothing is sent to YouTube before Apply, and the preview only reads.
2. The job processes the plan step by step, survives the closing of the page, and can be stopped
   and resumed.
3. The quota exhaustion pauses the job instead of failing every video.
4. The previous value of every change is logged and exportable.
5. The add/sync modes behave as in slice 018.
6. The routes refuse users without the capability, and the plan is recomputed on the server (not
   taken from the browser).
7. Unit tests cover the runner (steps, lock, backoff, quota pause, stop, resume) without WordPress.

## Open questions

1. (Decided.) The job recomputes the differences at each step instead of freezing the preview:
   always current, one more read per video (1 unit), and a video edited meanwhile on YouTube is not
   overwritten with stale values. The preview is therefore only an announcement of what the run
   is expected to do.
