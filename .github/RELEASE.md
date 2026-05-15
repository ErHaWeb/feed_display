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
   builds the TER artefact, verifies its contents, and publishes the package to
   TER.

## Manual backfill for an existing tag

If a tag already exists and has not been published yet, start the workflow
manually from `main` and provide the tag name in the `version` input.

With the GitHub CLI this looks like:

```bash
gh workflow run publish-ter.yml --ref main -f version=3.0.1
```

Only one workflow run per release version is allowed at a time. Parallel runs
for the same tag are serialized by the workflow `concurrency` group.

## Manual dry run for an existing tag

To validate packaging without contacting TER, start the same workflow manually
and set `dry_run=true`. The workflow then creates the TER artefact zip, uploads
it as a GitHub Actions artefact, and skips token validation and publication.

With the GitHub CLI this looks like:

```bash
gh workflow run publish-ter.yml --ref main -f version=3.0.1 -f dry_run=true
```

## Local artefact verification

The helper script validates the current release tag and generates the TER
comment locally:

```bash
bash Build/Scripts/prepareTerPublish.sh 3.0.1
```

To create and verify a local TER artefact with Tailor, install the pinned
version and use the packaging exclusions from
`Build/Tailor/ExcludeFromPackaging.php`:

```bash
COMPOSER_HOME="${PWD}/.Build/.composer" composer global require typo3/tailor:1.7.0
TYPO3_EXCLUDE_FROM_PACKAGING=Build/Tailor/ExcludeFromPackaging.php \
  php .Build/.composer/vendor/bin/tailor create-artefact 3.0.1 feed_display --path=.
bash Build/Scripts/verifyTerArtefact.sh tailor-version-artefact/feed_display_3.0.1.zip
```
