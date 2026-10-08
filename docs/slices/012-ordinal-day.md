<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Ordinal day placeholder

## Summary

The metadata templates get the placeholder `{ordinal_day}`: the day of the month of the post as
it is written in a date in the language of the site. `{ordinal_day} {date:F Y}` gives
"1er octobre 2026" in French and "1st October 2026" in English.

## Design

- A dedicated placeholder rather than an extension of the `{date:FORMAT}` format: PHP date
  formats have no ordinal for the languages that need one, and braces cannot be nested.
- The language is the one of the site (`determine_locale()`), as for `{date}`.
- Rules (`Metadata/OrdinalDay`, pure): French "1er" for the first, the plain number otherwise;
  English "st", "nd", "rd", "th" (11 to 13 are "th"); any other language, the plain number.
  More languages are added in this one class.

## Acceptance criteria

1. `{ordinal_day}` takes no argument and is listed in the settings page.
2. The examples above are produced; unknown locales give the plain number.
3. Unit tests cover the languages, the English exceptions and the placeholder.

## Status

- Status: implemented.
