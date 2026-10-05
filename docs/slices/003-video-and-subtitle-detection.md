<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Video and subtitle detection

## Summary

This slice finds the videos of a published post, and the subtitle tracks of each
video, by reading the rendered public page of the post. It returns, for each
video, the local file to upload and its subtitle files with their language.

## Context

The theme of the first user adds videos and subtitle tracks at display time from
attachments, so they are in the final page but not in the stored content. The
post is published before the upload (the description contains its permalink),
so its public page can be fetched. Reading the page makes the plugin independent
of how a given theme or editor inserts videos, and gives each subtitle file's
language from the standard `srclang` attribute instead of a naming convention.

## User story

As an administrator, I want the plugin to find the videos shown on a published
post and their subtitles so that I only have to choose which video to publish.

## Goals

- Fetch the public page of a published post safely.
- Extract videos, their sources and their subtitle tracks.
- Map each URL to a local file in the uploads directory, and to a media-library
  attachment when there is one.
- Keep parsing and URL mapping pure and unit-tested; keep fetching a thin layer.
- Hide the strategy behind an interface so others can be added later.

## Functional requirements

### 1. Detector interface

```php
interface VideoDetector {
	/** @return DetectedVideo[] */
	public function detect( int $postId ): array;
}
```

This slice provides one implementation, `RenderedPageDetector`.

### 2. Fetching the page

`PageFetcher` requests only the permalink of the given post, never an arbitrary
URL.

- The post must be published, of a viewable post type, and not password
  protected; otherwise the detector raises a typed *not eligible* exception.
- The request is anonymous (no cookies, no authentication headers), uses a
  timeout and follows redirects within the site.
- HTTP is performed through an injected closure, so tests do not need a server.
- Failures (transport error, status other than 200, empty body) raise typed
  exceptions with translatable messages so the editor can show them and offer a
  retry. Typical causes (blocked loopback requests, authentication in front of
  the site) are named in the message.
- SSL verification stays on by default; a documented filter lets an admin relax
  it for a local environment.
- The page is not cached.

### 3. Parsing

`PageParser` takes an HTML string and the page URL and returns
`DetectedVideo[]`, with no WordPress dependency.

- Use `DOMDocument` with libxml errors captured, so malformed HTML does not
  break detection.
- One `DetectedVideo` per `<video>` element, with:
  - `sources`: the `src` of the element and of its `<source>` children, in
    document order, each with its `type` when present;
  - `subtitles`: `<track>` children with `kind` `subtitles` or `captions` (a
    missing `kind` counts as `subtitles`); other kinds are ignored. Each track
    has `src`, `language` (`srclang`, normalised to lower case, or null when
    missing), and `label`;
  - `poster`, when present, kept for information.
- URLs are resolved against the page URL, honouring a `<base href>`; protocol-
  relative and relative URLs are supported.
- Videos without any source are ignored.

### 4. Mapping to local files

`LocalFileResolver` receives the uploads base URL and base directory and an
attachment-lookup closure (backed by `attachment_url_to_postid()` in WordPress).
For a URL it returns a `LocalFile` (path, URL, MIME type guessed from the
extension, size, attachment id or null) or null.

- The URL must be under the uploads base URL; `http`/`https` differences and a
  query string or fragment are ignored.
- The path is obtained by replacing the base URL with the base directory, after
  percent-decoding. A path containing `..` segments is rejected. Containment is
  checked on the normalised path, not on `realpath()`, because the uploads
  directory may legitimately contain a mount point or symlink.
- The file must exist, be readable and be a regular file with a video MIME type.
- For a video with several sources, the first source that resolves to an
  eligible local file is used; the other sources are kept for display only.
- A video whose sources are all external or unresolvable is returned flagged as
  *not uploadable*, with the reason, so the UI can list it but disable it.
- Subtitle tracks are mapped the same way; a track that does not resolve is
  reported but not usable. A track without a language is flagged so the user
  can be told it cannot be sent.
- Two `<video>` elements resolving to the same file are merged into one result,
  combining their subtitle tracks without duplicates.

### 5. Result shape

```php
DetectedVideo {
  string  $id;            // stable hash of the local path, used by later slices
  ?LocalFile $file;       // null when not uploadable
  ?string $reason;        // translatable reason when not uploadable
  SubtitleTrack[] $subtitles; // each with language, label, ?LocalFile, ?reason
  ?string $poster;
}
```

### 6. Code structure

Under `plugin/includes/WP_Scatter_Elsewhere/Detection/`: `VideoDetector`,
`RenderedPageDetector`, `PageFetcher`, `PageParser`, `LocalFileResolver`,
`DetectedVideo`, `LocalFile`, `SubtitleTrack`, and the typed exceptions.
`RenderedPageDetector` only composes the others, and the closures are wired in
`Admin/Bootstrap.php`.

## Non-goals

- Uploading videos or subtitles.
- Detection from the stored content, the media library or file naming
  conventions (other `VideoDetector` implementations, later if needed).
- Embedded players (YouTube, Vimeo iframes) and external video hosts.
- Converting or validating subtitle contents.
- Any user interface (the post-publish panel slice).

## Acceptance criteria

1. Given a page with one `<video>`, two `<source>` elements and two `<track>`
   elements, the parser returns one video with both sources and both tracks and
   their languages.
2. Relative, protocol-relative and `<base>`-relative URLs resolve correctly.
3. `kind="chapters"` and `kind="metadata"` tracks are ignored.
4. A URL under the uploads base URL resolves to the matching local path; a URL
   outside it, or containing `..`, does not.
5. A video whose only source is external is returned as not uploadable with a
   reason.
6. Two videos pointing at the same file are merged.
7. The detector refuses a draft, private or password-protected post and reports
   fetch failures with distinct, translatable errors.
8. The fetch sends no cookies or authentication.
9. Unit tests cover parsing, URL resolution and path safety with fake
   closures, and the fetcher's error mapping without a network.

## Notes

- The page is fetched from the same server. Sites that block loopback requests,
  or put authentication in front of the whole site, will get a clear error;
  other detection strategies can be added behind the interface if needed.
- Status: implemented and unit-tested, including against an excerpt of a real
  blog page (`tests/fixtures/grenoble-salers.html`). `WordPressDetectorFactory`
  wires the closures to WordPress and is the only part not covered by tests.
  The loopback request has no TLS relaxation by default; the
  `wp_scatter_elsewhere_loopback_sslverify` filter exists for local environments.
- Other detection strategies, not implemented for now, that fit behind
  `VideoDetector`:

  | Strategy | Sees theme-generated markup | Needs HTTP | Risk |
  |----------|-----------------------------|------------|------|
  | Rendered page (loopback), implemented | yes | yes | low |
  | In-process template rendering: replace the main query with the post, `setup_postdata()`, locate the template, include it under output buffering | yes | no | medium: the theme runs outside a normal request (headers, `exit`, conditionals, globals, enqueued scripts, admin/REST/cron context) |
  | `the_content` filter on the post content | no, only what is in the content | no | low |
  | Attachments and the `foo-fr.vtt` naming convention | n/a | no | depends on the convention |

  No WordPress function renders the full page of a post from its ID without
  its public URL: the in-process route emulates the template loader.
- The `id` is derived from the local path so a video keeps the same identity
  across detections, which later slices rely on to store the YouTube video id and
  prevent a second upload.
