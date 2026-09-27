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

- [ ] https://wordpress.org/plugins/developers/add/: a short overview and
      `build/provemark-c2pa-check.zip`.
- [ ] Review within about 14 business days, by email. For questions about
      the bundled verifier's WPCS findings: `notes/wporg-review.md`. For
      the licences: `NOTES.md` (Measured, 2026-09-27).

The zip prepared for submission (2026-09-27): built from `d938e6c`
(CI green on every job), 279,526 bytes, SHA-256
`aa393b532a3971bb3e8cadb609dc14cec7901be9128f96876fc0195b7a57b7d6`.
Keep it unchanged after submitting.

The overview for the form:

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

- [ ] `trunk/`: the **contents of the build**
      (`build/provemark-c2pa-check/`), not the git repository. The main
      file at the top of `trunk/`, not in a subfolder. Anything in SVN is
      shipped to every user, so only what the zip holds.
- [ ] `assets/` (top level, beside `trunk/` and `tags/`): the eight files
      in `.wordpress-org/` (`icon-128x128.png`, `icon-256x256.png`,
      `banner-772x250.png`, `banner-1544x500.png`, `screenshot-1..4.png`),
      not `.wordpress-org/source/`. One screenshot per caption line in
      `readme.txt` (tested: `tests/Unit/ReadmeTest.php`). Limits: icons
      1 MB, banners 4 MB, screenshots 10 MB; ours are all under 250 KB.
- [ ] `svn cp trunk tags/<version>`; `Stable tag` in `trunk/readme.txt`
      equal to that version; commit both together.
- [ ] A matching git tag and, if wanted, a GitHub release (each on
      Maurice's go).

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
