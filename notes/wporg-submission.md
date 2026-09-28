# Checklist: submitting to wordpress.org

Written 2026-09-27. Not shipped. Every step that publishes anything is
Maurice's to take. Sources, read 2026-09-27 (developer.wordpress.org):
[Planning, Submitting, and Maintaining Plugins](https://developer.wordpress.org/plugins/wordpress-org/planning-submitting-and-maintaining-plugins/),
[How to Use Subversion](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/),
[Plugin Assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).
Items marked *reasoned* are not stated on those pages; check them then.

## 1. Before submitting (Maurice)

- [x] A wordpress.org account with an email address that is read
      regularly; allow mail from `plugins@wordpress.org` (the review
      comes by email).
- [ ] Decide the version for the first release (now `0.1.0`); if it
      changes: the plugin header, `Stable tag` in `readme.txt`, the
      changelog and upgrade notice.
- [ ] Decide whether the GitHub repository becomes public (not required
      by wordpress.org).
- [ ] The name. The slug comes from the submission and **cannot be changed
      afterwards**; the display name can. Expected slug:
      `provemark-c2pa-check` (*reasoned*: derived from the plugin name).
      "C2PA" is the coalition's name; the guidelines ask not to use
      others' trademarks in a way that suggests endorsement (*reasoned*: a
      reviewer may ask; "Provemark" comes first for that reason).
      Outcome: the first review (2026-09-28) rejected "Provemark" as a
      possible third-party brand and asked for C2PA after "for"; renamed
      to Tracefern Image Check for C2PA (SPEC-020), new slug
      `tracefern-image-check-for-c2pa` to be requested in the reply.

## 2. Prepare the release (Claude, on request)

- [x] `Contributors:` in `readme.txt` with Maurice's wordpress.org
      username (case-sensitive).
- [x] `Tested up to` equals the current WordPress major version
      (2026-09-27: 7.1; latest release 7.1.2 per api.wordpress.org).
- [ ] `composer check`, `composer test:integration`,
      `composer test:multisite` green; then `composer test:release`,
      which builds `build/provemark-c2pa-check.zip` and runs Plugin Check
      on it (no errors, no warnings).
- [x] `readme.txt` through the
      [readme validator](https://wordpress.org/plugins/developers/readme-validator/)
      (2026-09-27, the readme of `8083358`: no errors, no warnings; notes
      only: the tags `c2pa` and `provenance` are not widely used, no donate
      link).
- [ ] The zip installed by hand once on a clean WordPress (the release
      environment, port 8890) and an image uploaded.

## 3. Submit (Maurice)

- [x] **Submitted by Maurice on 2026-09-27**, the zip of `d938e6c` (SHA-256
      `aa393b53…7a57b7d6`; a copy kept in `build/submitted/`, which the
      build does not clear).
- [x] **A new version uploaded by Maurice on 2026-09-27**, before the review
      began: the zip of `3566af8`, with verifier v0.2.5 (four wrong
      `Trusted` fixed there), 286,674 bytes, SHA-256
      `8c7d7548e4aa1193729cd48aa9813249f8255b41b5c34e24a3507933a50b693e`
      (CI green on every job; kept in `build/submitted/`). Reviewed in the
      first round (below).
- [x] **Frozen until the reviewer replies** (decided by Maurice,
      2026-09-27): no more uploads before the review, as repeated uploads
      would look unsettled. Changes wait for the reviewer's reply (then one
      new version with them) or for 0.1.1: first SPEC-019, the Development
      section in `readme.txt`. `build/provemark-c2pa-check.zip` may be newer
      than what is under review; the submitted zips are in `build/submitted/`.
      Update 2026-09-28: the reviewer replied; SPEC-019 went along in the
      corrected upload below, with SPEC-020 to SPEC-023. Frozen again until
      the next reply.
- [x] **First review received 2026-09-28** (automated pre-review, Review ID
      `P0TDX376861HGN`): the name ("ProveMark" possibly a third-party brand,
      "C2PA" not after "for"), ownership, guideline 11 (notices), the
      `.pem` files, `register_setting()` sanitization. Handled in SPEC-020
      to SPEC-023: renamed to Tracefern Image Check for C2PA, the GitHub
      repository renamed, named sanitize callbacks, the trust notice on
      four screens, the settings page called Tracefern.
- [x] **The corrected version uploaded and the reply sent by Maurice on
      2026-09-28**: the zip of `432a64b` (CI green on every job; release
      suite and Plugin Check clean), `tracefern-image-check-for-c2pa.zip`,
      290,831 bytes, SHA-256
      `66335707d04476b30757469b646ad8322f69a35b2e1de3e89f97c01307a804af`
      (kept in `build/submitted/`). The reply, in the same thread, asked for
      the slug `tracefern-image-check-for-c2pa` and explained "provemark"
      (own GitHub organisation and library) and the `.pem` files. Not sent,
      kept for a question: the paragraph on the bundled library in
      `notes/wporg-review.md`. This is the version under review; the thread
      now waits in the reviewer's queue.
- [x] https://wordpress.org/plugins/developers/add/ (read 2026-09-27,
      logged in): eight confirmations to tick, each a statement by the
      account owner (the FAQ and the guidelines read; Plugin Check run; the
      name not confusingly similar, searched for; permission and an account
      that represents the owner; no trialware; the rejection rules
      understood), and the zip (at most 10 MB). **There is no field for an
      overview**; the reviewer reads `readme.txt`.
- [x] Ownership of the name: the form names the account's e-mail address
      as how ownership is verified, and names starting with a brand may
      only be submitted by its verified owner. The account's address is
      not on a Provemark domain. Decided by Maurice (2026-09-27): submit
      as is, and show the GitHub organisation `provemark` (which also
      publishes `provemark/c2pa-verifier`) if the reviewer asks.
- [x] Review: the page said 185 plugins awaited a first review and gave
      1 to 10 days (2026-09-27), by email. For questions about the bundled
      verifier's WPCS findings: `notes/wporg-review.md`. For the licences:
      `NOTES.md` (Measured, 2026-09-27).
- [x] **Approved 2026-09-28** under the slug `tracefern-image-check-for-c2pa`
      (Review ID `APPROVED tracefern-image-check-for-c2pa/mauricevanloon/28Sep26/T3`),
      the zip uploaded that morning (SHA-256 `66335707…07a804af`, kept in
      `build/submitted/`). SVN:
      https://plugins.svn.wordpress.org/tracefern-image-check-for-c2pa

The zip prepared for submission (2026-09-27): built from `d938e6c`
(CI green on every job), 279,526 bytes, SHA-256
`aa393b532a3971bb3e8cadb609dc14cec7901be9128f96876fc0195b7a57b7d6`.
Keep it unchanged after submitting.

An overview, not asked by the form; kept for the reviewer's e-mail or a
later description:

```text
Provemark C2PA Check verifies the Content Credentials (C2PA) of JPEG, PNG and WebP images uploaded to the Media Library, and shows the result in a Media Library column, a filter, and the attachment details: "Verified: trusted signer", "Intact: signer not trusted", "Does not verify", "No Content Credentials" or "Could not be checked", with the signer and, when a verified manifest says so, "AI-generated (signed)".

Each upload is checked in the background through WP-Cron, on the original file, so a check can never break an upload. A WP-CLI command re-checks existing images.

The plugin only reads: it never signs anything, holds no keys, blocks no uploads and makes no network calls. Verification is done by the bundled MIT-licensed library provemark/c2pa-verifier (same author), prefixed with Strauss. The default trust anchors are the C2PA conformance programme's public trust lists (CC BY 4.0, credited in readme.txt), bundled as plain-text certificates in trust/.

"C2PA" is used descriptively; the plugin is not made or endorsed by the Coalition for Content Provenance and Authenticity.
```

## 4. After approval: the SVN repository

The approval email gives the SVN address. The SVN password is separate
from the account password:
https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password

- [x] `trunk/`: the **contents of the build**, not the git repository.
      The main file at the top of `trunk/`, not in a subfolder. Anything in
      SVN is shipped to every user, so only what the zip holds.
- [x] `assets/` (top level, beside `trunk/` and `tags/`): the eight files
      in `.wordpress-org/` (`icon-128x128.png`, `icon-256x256.png`,
      `banner-772x250.png`, `banner-1544x500.png`, `screenshot-1..4.png`),
      not `.wordpress-org/source/`, with `svn:mime-type image/png`. One
      screenshot per caption line in `readme.txt` (tested:
      `tests/Unit/ReadmeTest.php`). Limits: icons 1 MB, banners 4 MB,
      screenshots 10 MB; ours are all under 250 KB.
- [x] `svn cp trunk tags/<version>`; `Stable tag` in `trunk/readme.txt`
      equal to that version; commit both together.
- [x] **0.1.0 released 2026-09-28**, SVN r3716634, committed by Maurice.
      `trunk/` is the unpacked approved zip itself: a fresh build of
      `c3c972d` differed only in the commit hash and Composer's autoloader
      class name, and no shipped file changed after `432a64b`. Measured
      afterwards: `diff -r` of the public download
      (`downloads.wordpress.org/plugin/tracefern-image-check-for-c2pa.0.1.0.zip`)
      against the approved zip is empty (the zip itself differs in bytes,
      as wordpress.org packs it again); the plugin API reports version
      0.1.0, requires 7.1, PHP 8.3, four screenshots, both banners and
      both icons; the public page returns 200.
- [x] A matching git tag: `v0.1.0` on `432a64b`, the commit the
      released zip names in `vendor/composer/installed.php`; pushed
      2026-09-28 on Maurice's go. A GitHub release: not made.

SVN is a release system: commit to it only for a release, as each commit
rebuilds every version's zip.

## 5. Each later release

- [ ] Changelog and upgrade notice in `readme.txt`; version in the header
      and `Stable tag`.
- [ ] Section 2 again; then `trunk/` updated from the new build, a new tag,
      `Stable tag` pointing at it, one commit.
- [ ] With every WordPress major version: test, then `Tested up to` (this
      alone can be changed in `trunk/readme.txt` without a new version;
      *reasoned*).
- [ ] New C2PA trust lists or a new verifier version: a normal release
      (`trust/README.md`, `tests/wpcs-verifier-baseline.json` reviewed).
