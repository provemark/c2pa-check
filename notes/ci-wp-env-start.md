# CI: where starting wp-env goes

Measured 2026-09-26 on GitHub Actions (`ubuntu-24.04`, integration job,
PHP 8.3) with `wp-env start --config .wp-env.test.json --debug` on a
temporary branch (`ci-measure-wp-env`, run 36248125858, deleted
afterwards). Total about 114 s:

| phase | time |
|---|---|
| wp-env itself starting | 9 s |
| pulling the MariaDB and phpMyAdmin images | 26 s |
| starting MariaDB, downloading WordPress 7.1.2 | 10 s |
| cloning WordPress's PHPUnit suite (not used here) | 10 s |
| building wp-env's own images (base image, `apt`/`apk`, Composer, a global PHPUnit) | 52 s |
| configuring WordPress | 5 s |

Read in wp-env's `lib/runtime/docker/index.js`: without its own cache
(always the case on a fresh runner) it pulls all images and runs `up
--build --force-recreate`, so images restored from a cache would be built
again; skipping the build would mean also restoring wp-env's config cache
and the database volumes. Reasoned: a Docker image cache could save the
pulls (26 s plus about 10 s of base images) but restoring over 1.5 GB of
images takes 20–30 s itself. Decided by Maurice: no Docker cache.

The experiment job failed after the measurement: calling
`node_modules/.bin/wp-env` directly left `wp-env` off the PATH for the
`afterStart` lifecycle script (`npm run` adds it). Not on `main`.
