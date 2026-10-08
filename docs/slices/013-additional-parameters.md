<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Additional parameters

## Summary

The settings of the uploads get the parameters of YouTube Studio that were still fixed: the
**category**, whether the video can be **embedded**, whether its **statistics are public**,
the **made for kids** declaration, and whether the **subscribers are notified**. They apply to
every upload.

## Design

- Category: the number of a YouTube category (default 22, People & Blogs), validated as a
  number; the settings page lists the usual ones. Listing the categories of the channel region
  with `videoCategories.list` is possible later.
- Options (defaults as YouTube's): embeddable yes, public statistics yes, made for kids no,
  notify subscribers yes. They are copied into the upload job like the other metadata, so a
  retried job keeps what was decided at the time of the request.
- `notifySubscribers=false` is a parameter of the insert request, not a property of the video.
- Location is not handled: it needs coordinates per post and the blog has no such field yet.
- The existing videos are not changed by these settings (`apply-metadata` still updates only
  what it is asked to).

## Acceptance criteria

1. The settings page saves and shows the five parameters; an invalid category is refused.
2. A new upload sends them (`categoryId`, `status.embeddable`, `status.publicStatsViewable`,
   `status.selfDeclaredMadeForKids`, `notifySubscribers`).
3. Unit tests cover the defaults, the validation and the request.

## Status

- Status: implemented.
