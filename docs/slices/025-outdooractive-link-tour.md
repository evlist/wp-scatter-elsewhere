<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Outdooractive: link a tour to a post

**Draft.** This slice and the next one start a second target after YouTube: Outdooractive, where the
author records their hikes and has little time to document them. Several points depend on facts to
check on the site (see *Open questions*), so this is a first design, not a decision.

## Summary

This slice lets the author **link a tour recorded on Outdooractive to the post of the blog that
tells the same hike**, as the videos are linked to their YouTube video: by the address of the tour,
with a suggestion by title and date, and a bulk linking. It only records the link, and does not
talk to Outdooractive.

## Context

- Two situations exist. When the hike was **recorded with the application of Outdooractive**, the
  trace already exists and only its description is missing (slices 026 and 027). When it was recorded
  elsewhere (a watch, another application), the GPX file can be imported on the **website**, which lets
  the author choose between a planned route (a "parcours") and a recorded trace (the Android
  application always imports as a route), and the **bulk ZIP import** can carry the texts and the
  photos as well (slice 028). The link of this slice serves both.
- Outdooractive offers no documented way for a user to edit their own tour by an API: the Data API is
  a licensed, read-oriented API for partners. The plugin therefore does **not** read or write
  anything on Outdooractive in this slice.
- Linking is still useful: it lets slice 026 prepare the right text for the right tour and lets the
  blog show the Outdooractive tour of a hike.

## User story

As an author, I want to say which Outdooractive tour belongs to which post, so that I can document
the tour from the post and show the tour from the post.

## Goals

- Record, for a post, the address of its Outdooractive tour (a post may have several tours, and a
  tour may serve several posts, as a multi-day hike).
- Check the address without any account or API: it must be an Outdooractive tour address; the title
  is read from the public page when the tour is public, and typed in otherwise.
- Suggest the tour of a post, and the post of a tour, from the dates and the titles when the author
  gives a list.
- Expose the link on the blog: a template function and a shortcode like the YouTube ones.

## Functional requirements

### 1. The link

- Stored in the post meta `_wp_scatter_elsewhere_outdooractive`, a list of
  `{tour_id, url, title, linked_at}`; the tour ID is the number in the address
  (`https://www.outdooractive.com/<language>/route/<activity>/<region>/<title>/<id>/`).
- The accepted addresses are those of outdooractive.com (any language and subdomain) that contain
  a tour ID, with or without tracking parameters; others are refused with the reason.
- A tour already linked to another post is shown with a choice to move or to add (a multi-day hike
  is several posts for one tour, or one post for several tours, so adding is allowed, unlike the
  videos, where a YouTube video belongs to one video of the blog).

### 2. In the editor

A panel **Outdooractive** (next to the YouTube one), for posts and pages: the linked tours with their
title and address, **Link a tour** (paste an address, optional title) and **Unlink**. Nothing is
sent to Outdooractive.

### 3. WP-CLI

`wp scatter-elsewhere oa-link <post-id> <address> [--title=<title>]`, `oa-unlink <post-id> [<tour-id>]`,
and `oa-tours [--post=<ids>]` listing the links.

### 4. On the blog

`wp_scatter_elsewhere_outdooractive_url( $post_id )` and the shortcode
`[scatter_elsewhere_outdooractive_link text=""]`, which print the address (or a link) of the tour of
the post, like the YouTube ones, and nothing when there is none.

### 5. Suggestions

Given a list of tours (a file the author exports or pastes: titles, dates, addresses; for example the
CSV of the tours of the account when one is available), `oa-suggest` matches them to posts with the
matcher of slice 016 (the title folded, the date within a few days) and reports the proposals; a bulk
`--apply` links the confident ones. Without such a list there is nothing to suggest from, since the
plugin does not read the account.

### 6. Code structure

A first move towards several targets, kept small:

- `Targets/RemoteLink.php`-like value object and store for "a remote object linked to a post" would
  be shared with the YouTube publication records **only if** it stays simpler than two
  independent stores; the first implementation keeps `Outdooractive/` separate
  (`TourAddress`, `TourLink`, `TourLinkStore`, `TourSuggester`) and reuses `Text\Folding` and
  `Matching\VideoMatcher`'s date and title rules by extraction, not by copy.
- `Outdooractive/WordPressFactory.php`, `Admin` panel script `assets/js/editor-outdooractive-panel.js`,
  REST routes `post/<id>/outdooractive[/link|/unlink]`, WP-CLI commands.

## Non-goals

- Reading or writing anything on Outdooractive (no API, no login, no scraping of private pages).
- Creating a tour from a GPX file of the blog.
- Showing a map or the statistics of the tour.

## Acceptance criteria

1. A valid Outdooractive tour address is linked to a post, an invalid one refused with the reason.
2. A post can have several tours and a tour can serve several posts.
3. The panel, the command, the template function and the shortcode agree.
4. The suggestions from a list propose the right post for titles and dates like those of the blog.
5. Unit tests cover the address parsing (languages, parameters, rejected hosts), the store and the
   matching, without WordPress.

## Open questions

1. The exact shape of the tour addresses and of the identifier, in each language, to check on real
   addresses of the author's tours.
2. Whether the title of a public tour can be read from the page (a request from the server to a
   public page is acceptable for a public address; if the terms of use forbid it, the title is
   typed in).
3. Whether a list of the author's tours (an export of the account) exists to feed the suggestions.
4. Whether to share the storage of the links with YouTube (see the code structure): to decide when
   the second implementation exists.
