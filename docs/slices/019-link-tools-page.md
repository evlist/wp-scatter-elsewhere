<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Administration page: link the old posts

## Summary

This slice puts the **bulk linking** of slice 017 in the administration, under **Tools**, so
that it can be used without WP-CLI: scan the posts, review the proposed matches, tick the ones
to link, and apply. Nothing new is decided by the page: it uses the same classes and the same
rules as the command.

## Context

Slice 017 is a command for administrators with shell access. Many sites do not have it, and a
review table is more comfortable in a browser than a text report. The page must also cope with
the limits of a web request: scanning a post means fetching its public page, so a blog with
hundreds of posts cannot be scanned in one request.

## User story

As an administrator, I want a page where I see the old posts, the YouTube video proposed for
each, and link the ones I agree with in a click.

## Goals

- The same scan, decisions and checks as `link-existing`, in a page of **Tools**.
- A scan in small steps, with a progress bar, that survives slow pages.
- A review table with the reasons, where the confident matches are pre-ticked and the others can
  be ticked by hand or replaced by a video chosen from the channel.
- An undo list for what has been linked.

## Functional requirements

### 1. The page

**Tools → Scatter Elsewhere – Link videos** (capability `manage_options`, filter
`wp_scatter_elsewhere_capability`), shown only when the plugin is connected; otherwise a link to
the settings.

- **Filters**: posts since a date, only posts without a link (default), number of posts per scan,
  minimum confidence to pre-tick (high by default).
- **Scan** button: the page asks the server for the next chunk of posts (5 at a time) until the
  list is finished, and shows a progress bar, the number of videos found, and a **Stop** button.
- The channel is read once at the start of the scan (slice 015, cache of one hour) and the
  quota use is stated ("about N units").

### 2. The review table

One row per video of a post: the post (edit link), the video of the blog, the proposed YouTube
video (title, privacy, date, link to YouTube), the reasons, the confidence, the decision.

- Rows "would link" are ticked; "needs a decision" rows are not, and their reason is explained
  (several candidates, claimed by another post, low confidence).
- On a row, **Choose another** opens the search of slice 015 for that row; the choice replaces the
  proposal and ticks the row.
- Rows "already linked" and "no candidate" are in a collapsed section.
- **Link the ticked videos** applies them with the checks of slice 014, one request per video
  (in chunks, with progress), and shows the outcome per row (linked, refused with the reason).
  A YouTube video ticked on two rows is refused for both before anything is sent.

### 3. Undo

After an application, the page lists what was linked in this run with an **Undo** button per row
and **Undo all this run**. The run log (post, video, YouTube id, time) is kept in an option for
the last runs (20 entries); it is only an aid, nothing else depends on it.

### 4. REST routes

`POST /wp-scatter-elsewhere/v1/tools/link-scan` (filters + cursor → next chunk of decisions and
the new cursor), `POST /tools/link-apply` (a list of {post, video, youtube id} → outcomes),
`POST /tools/link-undo`. All check the capability and a REST nonce, and re-check on the server
everything the page claims (the page is never trusted for a decision).

### 5. Code structure

- `Matching/BulkScan.php`: the scan as a resumable cursor over the posts (pure part: the cursor and
  the pairing; the fetch of the pages is injected), shared with the command, which then
  becomes a loop over the same scan.
- `Admin/LinkToolsPage.php`: the menu, the page and the script.
- `Rest/ToolsController.php`: the three routes.
- `assets/js/link-tools.js`: no build step, as for the editor panel (wp.element, apiFetch).

## Non-goals

- Linking without review (the command with `--apply` does that, on purpose).
- A background scan that continues after the page is closed.
- Updating the videos (slice 020).

## Acceptance criteria

1. The page scans in chunks, shows progress, and can be stopped and restarted.
2. The confident matches are pre-ticked and the others are not; the reasons are shown.
3. Linking uses the checks of slice 014 and reports each refusal on its row.
4. A YouTube video claimed twice is never linked by the page.
5. The last run can be undone, in whole or per row.
6. The routes refuse users without the capability.
7. Unit tests cover the cursor and the scan chunking without WordPress; the script is tested
   with the jsdom harness as the editor panel is.

## Open questions

1. A progress bar driven by the browser stops when the tab is closed; a WP-Cron scan (as the
   uploads) would not. It is left out here because the review needs the author at the screen
   anyway.

## Status

- Status: implemented. `Matching/BulkScan` (chunks and cursor over a list of posts, pure),
  `BulkApplier` (refuses a YouTube video ticked twice, goes through the link service) and `LinkRunLog`
  (the last 20 runs, for the undo) are pure and tested; `Rest/ToolsController` has the routes
  `tools/link-scan`, `link-apply`, `link-undo` and `link-runs`; `Admin/LinkToolsPage` and
  `assets/js/link-tools.js` are the page, tested with the jsdom harness.
- The command of slice 017 and the page share `Matching\WordPressFactory::postsWithVideo()`.
- The conflicts between rows (a YouTube video proposed to several videos of the blog) are detected
  by the page over all the rows scanned, since the chunks are examined separately; the server
  refuses a video ticked twice in the same request and the link service refuses one that is
  linked elsewhere.
- An undo removes a link only if it still points at the YouTube video logged for the run.
- The page offers "Choose another" on every row, which searches the unlinked videos of the channel
  with the route of slice 015.
