# SPEC-025: The plugin page says what sets the plugin apart

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The plugin directory has some thirty plugins that label AI images, most
for the EU AI Act's Article 50, and many say they "detect" AI images
through C2PA. A visitor comparing them cannot tell from `readme.txt` that
this plugin does something they do not: it verifies the credentials
instead of trusting what the file says about itself. The short
description ("Checks the Content Credentials…") reads like theirs.

Measured 2026-09-28, in WordPress Playground with the default settings,
four such plugins from the directory against the fixtures of SPEC-024
(a Pixel 10 photo, an OpenAI image, the same image with one byte of image
data changed, an Amazon Titan image, an unsigned image):

- three of the four labelled the changed AI image exactly as the intact
  one; the fourth did not see the C2PA data at all;
- two of the four labelled the camera photo as AI-generated, one because
  any C2PA manifest counts as AI, one because the manifest's
  `c2pa.created` action does.

In the C2PA code of thirteen directory plugins that read C2PA, no call
that checks a signature or a hash was found (a code search; measured that
the code is absent, not how each plugin behaves).

The page should say this plainly, without naming anyone and without
claims the plugin cannot back: what it checks, and the two ways a
check-by-presence goes wrong. Directory guideline 9 ("Developers and
their plugins must not do anything illegal, dishonest, or morally
offensive") and the community's expectations argue against naming or
disparaging other plugins; a description of the two patterns is enough,
and anyone can repeat the test with the public fixtures.

## Scope

**In scope**, `readme.txt` only:

- The short description (the line under the name in search results):

  > Verifies the Content Credentials (C2PA) of uploaded images (signature,
  > image hash and signer) and shows the verdict in the Media Library.

  137 characters; the directory allows 150.

- A first paragraph in `== Description ==`:

  > **Verified, not just detected.** Finding a C2PA manifest in a file is
  > easy; knowing whether it is genuine is not. A manifest can be copied
  > onto another image, and an image can be changed after it was signed
  > while its manifest still claims what it did before. Tracefern checks
  > the signature, the hash that ties the manifest to the image's own
  > bytes, and the signer's certificate against the C2PA trust lists.
  > Only when the signature and the hash hold does it show "AI-generated
  > (signed)", and only when the signer is also on the trust list does it
  > say "Verified".

  The last sentence follows SPEC-003: the label shows on `Trusted` and on
  `Valid` ("Intact: signer not trusted"), both of which mean the
  signature and the hash hold; "Verified" is `Trusted` only. (The draft
  shown to Maurice said "only when all of that holds … only then does it
  show" the label, which would have claimed too much.)

- A first question in `== Frequently Asked Questions ==`:

  > = How is this different from plugins that label AI images? =
  >
  > Many plugins look for C2PA or IPTC metadata and label an image as
  > AI-generated when they find it. Some count any C2PA manifest as AI,
  > so a camera photo with Content Credentials is labelled AI-generated.
  > Others read what the manifest claims without checking it, so an AI
  > image that was changed after signing keeps its label. Tracefern
  > verifies first: a camera photo stays a camera photo, and a changed
  > image says "Does not verify" and loses the label. It adds no badges
  > to your pages; it gives you the verdict a label can rely on.

- The same text in `trunk/readme.txt` and in the readme of the stable
  tag in SVN (see Open questions).

**Out of scope** (each needs its own spec before it may be built)

- Naming, linking or comparing any other plugin anywhere public.
- A sentence pointing to the Live Preview: added when the preview is
  public (SPEC-024), not before, so the page never points at a button
  that is not there.
- Tags, screenshots, banner, or any code.
- A hook through which label plugins could read the verdict.
- Publishing the measurement itself (an article, a table).

## Behavior

- **AC1 — The short description says "verifies"**
  - Given `readme.txt`
  - When the Unit test reads the first line after the header block
  - Then it equals the short description in Scope, and the existing
    limit (150 characters) still holds.

- **AC2 — The description opens with what is verified**
  - Given `readme.txt`
  - When the first paragraph of `== Description ==` is read
  - Then it starts with `**Verified, not just detected.**` and names the
    signature, the hash and the signer's certificate; its last sentence
    reads: Only when the signature and the hash hold does it show
    "AI-generated (signed)", and only when the signer is also on the
    trust list does it say "Verified".

- **AC3 — The FAQ answers the comparison first**
  - Given `readme.txt`
  - When the questions of `== Frequently Asked Questions ==` are listed
  - Then the first is "How is this different from plugins that label AI
    images?", and its answer names both patterns (any manifest counted
    as AI; a claim read without checking it) and both outcomes ("a
    camera photo stays a camera photo"; "Does not verify" and the label
    lost).

- **AC4 — No link to another plugin** *(error path)*
  - Given `readme.txt`
  - When every URL in it is listed
  - Then each is on an allow-list: the licence pages
    (`opensource.org/licenses/`, `creativecommons.org/licenses/`),
    `github.com/provemark/`, `github.com/c2pa-org/`, and this plugin's own
    page `wordpress.org/plugins/tracefern-image-check-for-c2pa`; a URL
    that is not, such as another plugin's page or site, fails the test
    with that URL. (Names are not tested: no list of other plugins is
    kept in the repository, by Maurice's decision of 2026-09-28; the
    text itself names none, which review of this spec checks.)

- **AC5 — The claims stay backed**
  - Given the fixtures of SPEC-024
  - When the SPEC-024 tests run (unchanged)
  - Then they still show the two outcomes the FAQ states: the Pixel 10
    photo without the AI label, and the changed OpenAI image as `Invalid`
    without it. This spec adds no test for it; it relies on SPEC-024
    AC2–AC3 and names them here so a change there is seen to touch this
    text.

## References

- WordPress: [How your readme.txt works](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
  ("The Stable Tag points to a subdirectory in the /tags directory": the
  page is built from `tags/0.1.0/readme.txt`);
  [Using Subversion](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)
  ("Instead of pushing your code directly to a tag folder, you should
  edit the code in trunk … and then copy the code from trunk to the new
  tag"); [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
  (9). All read 2026-09-28.
- Measured 2026-09-28: four AI-label plugins from the directory, in
  Playground, each at its current version, installed from the directory
  with its defaults; the five fixtures imported, due cron events run, and
  every non-core attachment meta read. The code search of thirteen
  directory plugins that read C2PA. Which plugins they were is not
  recorded in the repository (Maurice's decision).
- Reasoned: that a readme-only edit of the stable tag is accepted without
  a new version (widely done for "Tested up to"; not stated in the
  handbook, which only discourages pushing *code* into a tag).

## API sketch

Not applicable: text in `readme.txt` and a Unit test in
`tests/Unit/ReadmeTest.php`.

## Open questions

- **How it goes live** (blocker for the SVN step, not for building):
  (a) edit `readme.txt` in `trunk/` and in `tags/0.1.0/`, no new version:
  no update notice for sites, the page changes at once; the handbook does
  not forbid it for a readme but does not say so either. (b) Release
  0.1.1 with only this change: follows the handbook exactly, but every
  site gets an update for a text change. Proposal: (a).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | SPEC-024 AC2–AC3            | —                    |
