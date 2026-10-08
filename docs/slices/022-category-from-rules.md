<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Category from the term rules

## Summary

The term rules of slice 007 (playlist and keyword per term) get a third optional output: the
**YouTube category** of the video. A post in the "Travel" category of the blog can thus give
YouTube's "Travel & Events" category, while the other posts keep the default category of the
settings (slice 013).

## Design

- A rule row has three optional outputs: a playlist, keywords, and a category. A row may give
  any of them, as before.
- The category is **single-valued**. When several matching rules give a category, the **most
  specific term wins** (a sub-term before its parent, as with the inheritance of slice 007); on a
  tie, the first row of the table wins, and the settings page says so. No rule gives the default
  category of the settings.
- The category is a number (validated as in slice 013); the settings page offers a select of the
  usual categories and a free field.
- The upload sends it and the batch update (slice 018) compares and replaces it with the field
  `category`. There is nothing to "add or remove": the value is replaced by the one the rules
  give, or by the default when no rule applies.
- `TermRule`, `TermRuleValidator`, `TermRuleSettings` and `RuleMatcher` carry the new output,
  `RuleMatch` returns it, the metadata builder uses it; the existing stored rules stay valid
  (no category).

## Acceptance criteria

1. A rule can give a category; the setting table shows it as a column.
2. The most specific matching term decides; a tie is resolved by the order of the rows.
3. Without a matching rule, the default category is sent.
4. The category is part of the uploads and of the `category` field of the batch update.
5. Unit tests cover the validation, the specificity and the tie, and the stored rules without a
   category.

## Status

- Status: planned.
