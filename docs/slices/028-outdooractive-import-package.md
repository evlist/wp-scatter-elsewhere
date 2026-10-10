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

- The import page of the website accepts **a ZIP of GPX or FIT files**. It says that when the
  content comes from Komoot, the import "will also include the texts and the photos" of the tracks, and
  that the activity (hike, bike...) of FIT files is recognised. For the activities of other files, a
  **ZIP template** can be downloaded from the page.
- The website lets the author choose between a **route** and a **trace**; the Android application
  imports a file as a route only.
- After an import, a summary flags the duplicates and what could not be imported.
- Not known: the layout of the ZIP (folders, file names, the files that carry the text and the photos
  for the Komoot case), whether the plain GPX tags `<name>` and `<desc>` are read, the limits of size
  and number, and the way a ZIP of ordinary files is classified as traces or routes.
- A hike already recorded with the Outdooractive application is **not** a case for this slice: the
  import would create a duplicate (slices 026 and 027 serve it).

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

## First step: a manual experiment, before any code

1. Download the **template ZIP** from the import page and look at its content.
2. Make a small ZIP by hand with one GPX (with `<name>` and `<desc>`) and two photos, in the layout of
   the template, and import it as a trace: what is read (title, text, photos, activity)?
3. Report the layout and the result; this decides whether the slice is worth building.
