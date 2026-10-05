<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Metadata templates

## Summary

This slice lets the administrator define the YouTube **title** and
**description** as templates with placeholders, and provides a pure, unit-tested
renderer that turns a post into the final title and description.

## Context

The existing script builds the title from the post title and the description as
`<date>, <excerpt> Détails : <permalink>`. Other sites will want other formats,
other languages and other post elements, so the format must be configurable.
This slice has no YouTube call: it only produces strings, which later slices
send.

## User story

As an administrator, I want to define how a post becomes a YouTube title and
description so that videos are described consistently without editing each one.

## Goals

- Configure a title template and a description template in the settings.
- Resolve placeholders from the post data.
- Respect YouTube's limits and forbidden characters.
- Keep the renderer independent of WordPress so it can be tested with plain
  PHPUnit.

## Functional requirements

### 1. Template syntax

- A placeholder is `{name}` or `{name:argument}`, the same shape as the path
  patterns of wp-media-helper.
- A literal brace is written `{{` or `}}`.
- Anything else is literal text, including newlines.

### 2. Placeholders

| Placeholder | Value |
|-------------|-------|
| `{title}` | Post title as plain text (tags removed, entities decoded). |
| `{excerpt}` | Post excerpt as plain text (tags removed, entities decoded). |
| `{permalink}` | Post permalink. |
| `{date:FORMAT}` | Post date, `FORMAT` being a PHP date format, localised to the site locale. `{date}` alone uses the site date format. |
| `{author}` | Author display name. |
| `{terms:TAXONOMY}` | Names of the post's terms in that taxonomy, separated by `, `. |
| `{categories}`, `{tags}` | Shortcuts for `{terms:category}` and `{terms:post_tag}`. |

Because the post date is the event date for back-dated posts, `{date:…}` is the
post date, not the moment of upload. A locale-aware ordinal ("1er") is a later
slice.

### 3. Defaults

- Title: `{title}`.
- Description: `{excerpt}` followed by a blank line and `{permalink}`.

Both are used when the corresponding setting is empty.

### 4. Validation at save time

- An unknown placeholder name is rejected with a message naming it.
- An unbalanced brace is rejected.
- `{date:…}` requires an argument that is not empty when a colon is present.
- A rejected template is not saved and the previous value is kept.

### 5. Rendering rules

- The renderer receives a `PostData` value object (title, excerpt, permalink,
  date, author, terms by taxonomy) and a date-formatting closure, so it uses no
  WordPress function. The WordPress adapter builds `PostData` from a `WP_Post`
  and supplies a closure based on `wp_date()`.
- Unknown placeholders met at render time (for example after a plugin update)
  are left unchanged rather than raising an error.
- The result is normalised for YouTube:
  - `<` and `>` are removed (not allowed in titles or descriptions);
  - the title is trimmed, newlines become spaces, and it is cut to 100
    characters, ending with `…` when cut;
  - the description is cut to 5000 bytes at a character boundary;
  - an empty title falls back to the post title, then to a translatable
    placeholder text.
- The limits are constants in one place so they are easy to correct.

### 6. Settings

Two text fields (a single-line title template, a multi-line description
template) in the *YouTube* section of the settings page from slice 001, with
the placeholder list shown below the fields. Stored in the option
`wp_scatter_elsewhere_youtube_templates`:

```php
[
  'title'       => '{title}',
  'description' => "{excerpt}\n\n{permalink}",
]
```

### 7. Code structure

- `Metadata/PostData.php`: immutable value object.
- `Metadata/TemplateParser.php`: tokenises a template into text and placeholder
  nodes, reporting syntax errors.
- `Metadata/TemplateRenderer.php`: renders nodes with `PostData`.
- `Metadata/YouTubeTextNormalizer.php`: limits and character rules.
- `Settings/MetadataTemplateSettings.php`: load/save/validate with injected
  closures.
- WordPress glue (`WP_Post` → `PostData`, settings fields) in `Admin/`.

## Non-goals

- Live preview in the settings page.
- Per-post overriding of the templates.
- Conditional or looping template constructs.
- Tags/keywords and playlists (the term rules slice).
- The ordinal date placeholder.

## Acceptance criteria

1. The default templates produce the post title and `excerpt + permalink`.
2. The example `{date:j F Y}, {excerpt} Détails : {permalink}` renders the
   expected text for a given `PostData` and formatter.
3. `{{` and `}}` produce literal braces.
4. Unknown names and unbalanced braces are rejected at save time with a clear,
   translatable message; the previous value is kept.
5. A title longer than 100 characters is cut with `…`; a description longer
   than 5000 bytes is cut without splitting a multibyte character.
6. `<` and `>` never reach the output.
7. HTML in the title or excerpt is removed and entities are decoded.
8. Unit tests cover parsing, rendering, normalisation and validation without
   WordPress.

## Notes

- Status: implemented. The pure core (parser, renderer, normaliser, settings object,
  composer) is unit-tested. The WordPress glue (`Metadata/WordPressFactory`:
  `WP_Post` → `PostData` with `wp_date()`, and the settings fields on the
  *Scatter Elsewhere* page) is not covered by PHPUnit; it was only exercised
  with a minimal stub of the WordPress functions, not on a real WordPress.

- The YouTube limits (100 characters for a title, 5000 bytes for a description,
  no angle brackets) are confirmed against the current API documentation when
  the slice is implemented.
