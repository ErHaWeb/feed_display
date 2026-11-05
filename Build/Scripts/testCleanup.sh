#!/usr/bin/env bash

set -euo pipefail
readonly sourceRoot="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly fixture="$(mktemp -d "${TMPDIR:-/tmp}/feed-display-cleanup-XXXXXX")"
trap 'rm -rf -- "${fixture}"' EXIT
mkdir -p "${fixture}/Build/Scripts" "${fixture}/Build/SimplePie" "${fixture}/Classes" "${fixture}/playwright" "${fixture}/bin" "${fixture}/Libraries"
cp "${sourceRoot}/Build/Scripts/runTests.sh" "${fixture}/Build/Scripts/runTests.sh"
cp "${sourceRoot}/composer.json" "${fixture}/composer.json"
cp "${sourceRoot}/Build/SimplePie/composer.lock" "${fixture}/Build/SimplePie/composer.lock"
printf 'source\n' > "${fixture}/Classes/Keep.php"
printf 'source\n' > "${fixture}/playwright/keep.spec.ts"
# File cleanup must work with -b docker without contacting Docker at all.
printf '#!/bin/sh\ntouch "$CLEANUP_DOCKER_CALLED"\nexit 1\n' > "${fixture}/bin/docker"
chmod +x "${fixture}/bin/docker"
export CLEANUP_DOCKER_CALLED="${fixture}/docker-called"
export PATH="${fixture}/bin:${PATH}"
cd "${fixture}"

buildArtifacts=(.Build/vendor/autoload.php composer.lock composer.json.orig composer.json.testing)
otherArtifacts=(
    .cache/phpstan/cache .composer/vendor/autoload.php var/cache/code/test.php config/system/settings.php
    tailor-version-artefact/test.zip Documentation-GENERATED-temp/index.html
    .phpunit.cache/results .phpunit.result.cache .php-cs-fixer.cache Tests/Build/.phpunit.result.cache
    playwright-report/index.html test-results/result.json playwright/.auth/user.json
    Build/rector/composer.lock Build/fractor/composer.lock Resources/Private/Php/ComposerVendor/autoload.php
)
for artifact in "${buildArtifacts[@]}" "${otherArtifacts[@]}"; do
    mkdir -p "$(dirname "${artifact}")"
    printf 'generated\n' > "${artifact}"
done
ln -s "${fixture}/Classes" .Build/source-link
bash Build/Scripts/runTests.sh -b docker -s cleanBuild
for artifact in "${buildArtifacts[@]}"; do
    [[ ! -e "${artifact}" ]] || { echo "cleanBuild left ${artifact}" >&2; exit 1; }
done
for artifact in "${otherArtifacts[@]}"; do
    [[ -f "${artifact}" ]] || { echo "cleanBuild unexpectedly removed ${artifact}" >&2; exit 1; }
done
mkdir -p .Build/coverage
touch .Build/coverage/test.cov
bash Build/Scripts/runTests.sh -b docker -s clean
bash Build/Scripts/runTests.sh -b docker -s clean
for artifact in .Build "${otherArtifacts[@]}"; do
    [[ ! -e "${artifact}" ]] || { echo "clean left ${artifact}" >&2; exit 1; }
done
for directory in Tests/Build Resources/Private/Php Libraries; do
    [[ ! -e "${directory}" ]] || { echo "clean left empty ${directory}" >&2; exit 1; }
done
cmp composer.json "${sourceRoot}/composer.json"
cmp Build/SimplePie/composer.lock "${sourceRoot}/Build/SimplePie/composer.lock"
[[ -f Classes/Keep.php && -f playwright/keep.spec.ts && ! -e docker-called ]]
rm playwright/keep.spec.ts
bash Build/Scripts/runTests.sh -b docker -s clean
[[ ! -e playwright ]]
echo 'Verified cleanBuild scope, complete/idempotent clean, empty directories, preserved sources and bundle lock, and Docker-independent file cleanup.'
