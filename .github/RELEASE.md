# Release process

This document is a maintainer runbook for publishing `feed_display` releases
to TER.

Publishing to TER is automated with `.github/workflows/publish-ter.yml` and the
official TYPO3 Tailor CLI.

## Required repository secret

Add the repository secret `TYPO3_API_TOKEN` with the scopes
`extension:read,extension:write` and restrict it to `feed_display`.

## Standard release flow

1. Create the release commit and tag it as `x.y.z` without a `v` prefix.
2. Push the commit and tag to GitHub.
3. The workflow checks out the tagged commit, validates the version markers in
   `ext_emconf.php` and `Documentation/Settings.cfg`, generates the TER upload
   comment from the non-merge commit subjects since the previous release tag,
   installs the Classic SimplePie bundle from the tagged lockfile, builds the
   TER artefact, verifies its isolated runtime, and publishes the package to
   TER.

## Manual backfill for an existing tag

If a tag already exists and has not been published yet, start the workflow
manually from `main` and provide the tag name in the `version` input.

With the GitHub CLI this looks like:

```bash
gh workflow run publish-ter.yml --ref main -f version=3.0.2
```

Only one workflow run per release version is allowed at a time. Parallel runs
for the same tag are serialized by the workflow `concurrency` group.

## Manual dry run for an existing tag

To validate packaging without contacting TER, start the same workflow manually
and set `dry_run=true`. The workflow then creates the TER artefact zip, uploads
it as a GitHub Actions artefact, and skips token validation and publication.

With the GitHub CLI this looks like:

```bash
gh workflow run publish-ter.yml --ref main -f version=3.0.2 -f dry_run=true
```

## Local artefact verification

The helper script validates the current release tag and generates the TER
comment locally:

```bash
bash Build/Scripts/prepareTerPublish.sh 3.0.2
```

To create and verify a local TER artefact with Tailor, install the pinned
version and use the packaging exclusions from
`Build/Tailor/ExcludeFromPackaging.php`:

```bash
COMPOSER_HOME="${PWD}/.Build/.composer" composer global require typo3/tailor:1.7.0
TYPO3_EXCLUDE_FROM_PACKAGING=Build/Tailor/ExcludeFromPackaging.php \
  php .Build/.composer/vendor/bin/tailor create-artefact 3.0.2 feed_display --path=.
bash Build/Scripts/verifyTerArtefact.sh tailor-version-artefact/feed_display_3.0.2.zip
```

Run `composer build-extension` before manually creating an artefact. The complete
local dry run is also available through `Build/Scripts/runTests.sh -s artefact`.
It builds the locked bundle twice, checks that the lock does not change, and
loads SimplePie from the extracted ZIP in a fresh PHP process.

## SimplePie bundle and updates

The root Composer requirement is used by Composer installations. The separate
`Build/SimplePie/composer.json` and its committed `composer.lock` define the TER
runtime bundle. The generated `Resources/Private/Php/ComposerVendor` directory
is ignored by Git and is produced only for local Classic development and TER
packaging. GitHub source archives therefore require a build for Classic use.

Library updates are deliberate maintenance work, never part of a release build:

1. Update the bundle through the local runner:

   ```bash
   Build/Scripts/runTests.sh -s composer -p 8.2 -- --working-dir=Build/SimplePie update simplepie/simplepie --with-all-dependencies --no-plugins --no-scripts
   ```

2. Review the upstream changelog and lockfile diff. Keep PHP and SimplePie
   constraints aligned between the root and bundle manifests. If a fixed
   version becomes mandatory, raise both SimplePie minimum versions.
   Check for changes in SimplePie's autoload paths or runtime dependencies:
   TYPO3 13 Classic uses the mappings in `ext_emconf.php`.
3. Run the bundle security check and build:

   ```bash
   Build/Scripts/runTests.sh -s composer -- --working-dir=Build/SimplePie audit --locked
   Build/Scripts/runTests.sh -s libraries
   ```

4. Run unit and functional tests in the supported Composer matrix. Run the
   Classic tests on TYPO3 13.4 and 14.3, with PHP 8.2 and 8.5, and verify the TER
   ZIP. The Classic suite requires a matching `-s composerInstall -t 13|14`
   reference setup and downloads that exact official Core archive:

   ```bash
   Build/Scripts/runTests.sh -s artefact
   Build/Scripts/runTests.sh -s classic -t 14 -- tailor-version-artefact/feed_display_3.0.2.zip
   ```

5. Commit the bundle manifest and lockfile with the reviewed changes; generated
   vendor files are not committed. Deliver the library through the next
   extension release.

`composer rebuild-libs` recreates only the generated bundle. The library's BSD
license is retained in the TER package. Old release-tag backfills retain their
original PHAR and do not resolve dependencies using the current lockfile.

Composer installations update SimplePie through their project lockfile. Classic
installations receive the tested bundle with an extension update. No library
download occurs during a frontend, backend, or CLI request.
