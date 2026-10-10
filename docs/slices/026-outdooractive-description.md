<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Outdooractive: prepare the description of a tour

**Draft.** It depends on slice 025 and on the fields of the editing form of a recorded tour, which
are to be confirmed (see *Open questions*).

## Summary

This slice **prepares the text of the description of an Outdooractive tour from the post that tells
the hike**, and makes it easy to put it in the tour: composed from templates, reviewed and edited
before use, and copied in one click. The plugin never connects to Outdooractive; the author pastes
the text into the editing form of the tour, which is the part that took the time.

## Context

Documenting a recorded tour on Outdooractive means a title, a short description, a longer text and
practical information (access, start and end, difficulty, tips, equipment...). The author has
already written all this in the post. Since there is no API that edits a user's tour and an imported
GPX would become a planned route, the realistic help is a **ready-to-paste text**.

## User story

As an author, I want the description of my Outdooractive tour to be prepared from the post, so that I
paste it instead of rewriting it.

## Goals

- Compose the fields of the description from the post, with templates, like the YouTube metadata.
- Show the result as it would be pasted, let the author edit it, and keep the edits.
- Copy a field in one click, or all the fields, in the order of the form of Outdooractive.
- Respect the limits and the format of the fields (plain text or the formatting Outdooractive
  accepts, lengths, no HTML).

## Functional requirements

### 1. The fields and the templates

Settings **Outdooractive** (a section of the settings page), one template per field of the form,
with the placeholders of the metadata templates (`{title}`, `{excerpt}`, `{permalink}`,
`{date:FORMAT}`, `{ordinal_day}`, `{author}`, `{terms:...}`) and a few more that read the post:

- `{section:<heading>}`: the text under a heading of the post (for example "Itinéraire", "Conseils"),
  as plain text, so that a post written in sections feeds the fields of the form;
- `{first_paragraphs:N}`: the first N paragraphs;
- `{youtube}`: the address of the YouTube video of the post (slice 005), when there is one;
- `{words:N}` as a filter on any placeholder is **not** provided: lengths are handled by the limits
  of the field, shown as warnings.

The default fields (to confirm with the real form): title, short description, long description,
practical information (or "tips"), directions to the start, public transport. A field with an empty
template is not offered.

### 2. The panel

In the Outdooractive panel (slice 025), for each linked tour (or for the post if none is linked yet):

- the fields composed from the templates, each in a text area with its counter and the limit when
  it is known, **Copy** on each, and **Copy everything** (the fields in the order of the form,
  separated by their names) for the habit of pasting field by field;
- **Reset** of a field to the composed value; the edits are kept as overrides of that tour, with
  the same principles as slice 023;
- the link to the editing page of the tour (when the address is known), opened in a new tab.

### 3. Text rules

- Plain text, paragraphs separated by blank lines, no HTML (it is stripped and the entities decoded,
  like the YouTube description).
- The characters Outdooractive refuses, if any, are removed or replaced and shown in the warnings.
- A link to the post (`{permalink}`) can end the long description, so that the tour points back to the
  blog; it is the author's choice through the template.

### 4. WP-CLI

`wp scatter-elsewhere oa-text <post-id> [<tour-id>] [--field=<name>]` prints the composed text, so
that it can be piped to the clipboard (`| xclip`, `| pbcopy`).

### 5. Code structure

- `Outdooractive/DescriptionSettings` (the templates, validated like `MetadataTemplateSettings`),
  `DescriptionComposer` (pure, over `TemplateRenderer` extended with the new placeholders, which are
  also available to the YouTube templates), `SectionExtractor` (pure: the text under a heading of the
  content of the post), `DescriptionOverrides`.
- REST `post/<id>/outdooractive/text`, the panel script, the WP-CLI command.

## Non-goals

- Sending the text to Outdooractive (no API; see slice 025).
- Images, waypoints or the track itself.
- Translating the text.

## Acceptance criteria

1. The fields are composed from the templates and the content of the post, including the sections
   by heading.
2. The text is plain, within the limits of the fields, with warnings otherwise.
3. **Copy** and **Copy everything** put the right text in the clipboard.
4. The edits are kept per tour and can be reset.
5. The same placeholders work in the YouTube templates.
6. Unit tests cover the section extraction (headings of the block editor and of classic content),
   the new placeholders, the stripping, and the limits, without WordPress.

## Open questions

1. The fields of the editing form of a recorded tour, their names, order and limits, and whether
   they accept formatting: to be confirmed with a screenshot or a list. The default fields above
   are a guess.
2. How the author structures the posts of hikes (headings that can be used by `{section:...}`);
   a sample post would fix the first templates.
3. Whether the text should be offered in several languages when the post exists in several (the
   plugin works with the content of the post as it is).
4. The copy button needs the clipboard API, which is available on https pages; a selectable text
   area stays as the fallback.
