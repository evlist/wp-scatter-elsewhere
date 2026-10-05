<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Constraints and Working Rules

## Languages

- Interaction with IA agents may be in any language.
- Comments and documentation must stay in English.

## Internationalisation

- Every user-facing string must be translatable. No literal user-facing text may
  reach the browser untranslated.
- PHP strings must use the WordPress translation functions with the
  `wp-scatter-elsewhere` text domain, combined with the matching escaping
  function (`esc_html__()`, `esc_attr__()`, and so on) rather than escaping a
  translated value separately.
- JavaScript strings must be translated with the `@wordpress/i18n` package and
  shipped as JSON translation files. Scripts therefore must be registered as
  real asset files declaring the `wp-i18n` dependency and calling
  `wp_set_script_translations()`; inline scripts cannot be localised this way.
- Strings assembled from fragments are not acceptable. Use placeholders and
  `sprintf()` so translators receive complete sentences.

## Product and code constraints

- Full WordPress standards compliance is required.
- Full REUSE compliance with `GPL-3.0-or-later` is required.
- Product logic should not assume shell execution at runtime when a PHP
  integration exists.
- Use WordPress APIs rather than direct SQL queries against WordPress tables.
- Runtime code should stay under the PSR-4 structure rooted at
  `plugin/includes/WP_Scatter_Elsewhere/`.
- Configuration should live in the plugin settings page rather than in ad hoc
  runtime constants or shell-dependent setup.

## Delivery discipline

This repository follows a small-slice XP workflow:

- tiny vertical slices,
- test-first when practical,
- focused validation before widening scope,
- behavior-oriented tests,
- deletion of stale scaffolding rather than speculative accumulation.
