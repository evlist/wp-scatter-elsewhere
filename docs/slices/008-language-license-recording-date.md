<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Language, license and recording date

## Summary

This slice sends three more properties of a video to YouTube: its **language**
(the site language by default), its **license**, and its **recording date** (the
date of the post). They are sent with a new upload and can also be **applied to a
video that is already on YouTube**.

## Context

The existing workflow sets the language and the license on the command line and
could not set the recording date at all (YouTube Studio does not expose it).
Posts about a past trip are back-dated, so the post date is the date of the
event, which is exactly the recording date.

Updating a video that is already on YouTube costs far less quota than uploading
it again, and it allows testing on a video that has already been uploaded.

## User story

As an administrator, I want my videos to be published on YouTube with the right
language, license and recording date, without touching YouTube Studio.

## Goals

- Send the language, the license and the recording date with every upload.
- Apply them to a video that is already on YouTube, without losing its other
  properties.
- Make them configurable, with defaults that need no configuration.

## Functional requirements

### 1. Language

- The language is a setting on the settings page. When empty, it is derived from
  the site language (`get_locale()`).
- The derivation keeps the language code (`fr_FR` gives `fr`), except for Chinese
  and Portuguese where YouTube distinguishes regions and the region is kept
  (`zh_TW` gives `zh-TW`, `pt_BR` gives `pt-BR`). An unusable locale gives no
  language and nothing is sent.
- A language entered by hand must be a BCP-47 style code (letters, optionally
  followed by `-` and letters or digits).
- The same value is sent as the default language and as the default audio
  language of the video.

### 2. License

- A setting **Default license**: `youtube` (the standard YouTube license, the
  default) or `creativeCommon`, which is the "Creative Commons - Attribution" license
  of YouTube Studio (the only Creative Commons license YouTube offers).
- An explicit license can be given for one upload; the later editor slice asks it
  at publication time.

### 3. Recording date

- A setting **Send the post date as recording date**, enabled by default.
- The date sent is the post date (the event date for back-dated posts), as noon
  UTC of that day (`2026-10-05T12:00:00Z`). The time of day is not sent: YouTube
  shows a date, and noon UTC gives the same calendar day in every time zone, where
  a local midnight could slip to the previous day.

### 4. Upload

The three values are part of the upload request (`snippet.defaultLanguage`,
`snippet.defaultAudioLanguage`, `status.license`, `recordingDetails.recordingDate`).
The `recordingDetails` part is only requested when there is a date. They are
stored in the job, so a retried upload sends the same values.

### 5. Updating a video that is already on YouTube

`videos.update` replaces the parts it is given, and drops the properties that are
missing from them. The update therefore:

1. reads the current video (`videos.list`: snippet, status and recording details);
2. changes only the requested properties, keeping the others (title, description,
   tags, category, privacy, embedding, statistics visibility, location, and so on);
3. sends back the writable properties of the parts it read.

A video that does not exist, a refused authorisation and a quota error are
reported with a readable message. The update is synchronous and is not retried
automatically.

WP-CLI: `wp scatter-elsewhere apply-metadata <post-id> [<video-id>] [--fields=<fields>]`,
for the video recorded for the post (slice 005). `--fields` is a comma-separated
list among `language`, `license`, `recording_date`, `title` and `description`; the
default is the first three. Title and description are accepted so that the same
mechanism can later serve the updates of modified posts, which remain to be
scheduled (see `docs/slices/README.md`).

`wp scatter-elsewhere upload` gets a `--license=<license>` option.

### 6. Code structure

Under `plugin/includes/WP_Scatter_Elsewhere/`:

- `YouTube/VideoMetadata.php`: the properties sent with a video.
- `Metadata/LanguageResolver.php`: locale to YouTube language.
- `Metadata/VideoMetadataBuilder.php`: composes title, description, language,
  license and recording date from a post and the settings.
- `Settings/UploadSettings.php`: default privacy, default license, language and
  recording date switch.
- `YouTube/VideoUpdater.php`: the read, merge and update of a video.
- `YouTube/Upload/*`: the job and the uploader carry the new properties.
- WordPress glue: `Metadata/WordPressFactory.php`, `YouTube/WordPressFactory.php`,
  `Admin/SettingsPage.php`, `Cli/Command.php`.

## Non-goals

- Thumbnail, playlists, keywords and subtitles (other slices).
- Automatic updates when a post changes, and the review of the metadata before it
  is sent (ideas to schedule).
- Other properties such as the category, location, "made for kids" or statistics.
- Choosing the language or the license per post.

## Acceptance criteria

1. A new upload carries the language, license and recording date, and the stored
   job keeps them.
2. The language is derived from the site language when the setting is empty, and a
   language set by hand wins; an invalid language is refused.
3. The recording date is noon UTC of the post date, and is omitted when the switch
   is off.
4. The update keeps every property of the video that it was not asked to change.
5. The update changes only the requested properties and refuses an unknown video.
6. Invalid license and language values are refused when saving the settings.
7. Unit tests cover the resolver, the builder, the settings, the upload request
   and the updater without WordPress.

## Notes

- Status: implemented. The resolver, the builder, the settings, the upload request
  and the updater are unit-tested with fake HTTP closures (189 tests in total). The
  settings page was exercised with stubs of the WordPress functions. The WP-CLI
  commands (`upload --license`, `apply-metadata`) have only been syntax-checked.
  Nothing has been run against a real WordPress or YouTube yet.
- The exact request and response shapes of `videos.update` (in particular the
  read-only fields that must not be sent back) come from memory of the YouTube
  documentation and must be confirmed with a real update, which can be done on
  the private test video.
