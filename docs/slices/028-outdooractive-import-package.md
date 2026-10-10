<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Outdooractive: build an import package (trace, text and photos)

**Draft, and the least certain of the series.** It rests on the **bulk ZIP import** of the website
(*Ma Page > Importer des traces / parcours*), whose internal format is not documented in the pages
read so far. Nothing can be designed without the template ZIP offered on that page.

## Summary

For a hike that is **not recorded yet on Outdooractive**, this slice **builds from a post a ZIP file
that the bulk import of the website can take**: the GPX track of the hike, its title and description
from the templates of slice 026, and its photos from the selection of slice 027. The author uploads
the ZIP on the import page and chooses whether the content is created as a recorded trace or as a
planned route. The plugin never connects to Outdooractive.

## Context (what is known)

- The import page of the website accepts **a ZIP of GPX or FIT files**. For the activity (hike, bike...),
  the **template ZIP** of the page is only a set of **empty folders named after the activities**
  (Hiking, Mountaineering, Mountain biking, Cycling, Gravel biking, Road biking, Running, Downhill
  Skiing, Ski touring) and a `HOWTO.txt` in five languages: the tracks are sorted into the folders
  before the upload, and those left outside a folder are imported as hikes. It says nothing about
  texts or photos, and nothing suggests that a photo could be tied to a track in a generic ZIP.
- The page also says that a ZIP **from Komoot** is recognised and that its texts and photos are
  imported too. Komoot's own export format is not documented publicly (the support pages only
  describe GPX downloads, one tour at a time, with the geometry only), so the layout that Outdooractive
  reads is unknown.
- The website lets the author choose between a **route** and a **trace**; the Android application
  imports a file as a route only.
- After an import, a summary flags the duplicates and what could not be imported.
- Not known: whether the plain GPX tags `<name>`, `<desc>` (and `<link>`, `<wpt>`, `<extensions>`) are
  read, the limits of size and number, and the layout of the Komoot export.
- Most of the author's hikes were recorded with the Outdooractive application: importing the GPX again
  creates **a duplicate**, which is acceptable if it brings the text (and the photos), because the
  original can then be deleted by hand. The link of slice 025 then points at the new trace.

## User story

As an author who records my hikes with something else than Outdooractive, I want a ZIP made from my
post that creates the trace with its text and photos in one import.

## Goals

- Produce a ZIP that the import accepts, with the GPX, the title, the description and the photos.
- Make the choice between a trace and a route explicit in the instructions the page gives.
- Reuse the selection and the texts of slices 026 and 027, and the link of slice 025 (the tour that
  results is linked to the post afterwards, by its address).

## Functional requirements (to confirm)

1. **The GPX** comes from the post: a GPX file attached to it or linked in the content (a custom field
   or the first GPX attachment); the plugin does not create tracks. When the GPX has no name or
   description, they are filled from the title and the composed text, if Outdooractive reads them.
2. **The layout** copies the one of the Komoot export, or the template of the page, whichever carries
   the text and the photos; the plugin builds it with a writer that takes the layout as data so that a
   change of format is a change of data.
3. **The ZIP** is built on request, in a temporary file, served once to an authorised user and
   removed, as in slice 027.
4. **The panel** gets a button **Build the import package** with the list of what goes in (GPX,
   texts, photos) and the steps to follow on the import page, including the choice trace or route.
5. **WP-CLI**: `wp scatter-elsewhere oa-package <post-id> [--out=<file>]`.

## Code structure (sketch)

`Outdooractive/Package/PackageLayout` (data), `PackageBuilder` (pure over injected readers and ZIP
writer), reusing `DescriptionComposer`, `PhotoSelection` and `PhotoPackager`.

## Non-goals

- Creating a GPX from anything else, or editing the track.
- Updating a trace that already exists (a new import creates another one).
- Any call to Outdooractive.

## Acceptance criteria

1. A test import of the ZIP of a real post creates the trace with its title, description and photos,
   or the exact gaps are documented.
2. The ZIP follows the layout of the page (template or Komoot), with the files named as it expects.
3. The unit tests check the layout (names, order, content) without WordPress.

## Experiments to run before any code (in this order, each decides the next)

1. **Plain GPX with text.** Put a GPX of the author's in the `Hiking/` folder of a ZIP, with a `<name>`
   and a `<desc>` (and a `<link>` to the post) added to the track (`<trk>`), and import it **as a trace**
   on a test: are the title and the description filled? This is the cheapest path (the plugin only
   rewrites a GPX and zips it) and it also settles what can be put in the GPX. The plugin can prepare
   this GPX by hand before it is automated: copy the track, add the three tags.
2. **Waypoints with text.** If the track comes out without text, try the same text in a `<wpt>` and in
   `<extensions>` to see whether anything is kept.
3. **The Komoot layout.** If the author has a Komoot account, make a **data export** (or download the
   GPX and the details of one tour) and look at the layout of the ZIP, in particular where the title,
   the text and the photos of a tour are. Build the same layout with one hike of the blog and import
   it. This is the only known way to bring photos with the track; if the format is not reproducible
   (identifiers, signed files), the photos stay with slice 027.
4. **Duplicates.** Check what the import summary says when the same trace already exists, and whether
   the original can be deleted at once (the author's plan: import the enriched trace, then delete the
   original recorded by the application, losing nothing but the identifier of the old tour).

The result of the first experiment decides whether this slice is just "a GPX with the title and the
description added, in a ZIP" (small) or needs the Komoot layout (large and uncertain).
