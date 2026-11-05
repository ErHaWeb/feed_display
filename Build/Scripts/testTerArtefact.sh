#!/usr/bin/env bash

set -euo pipefail
readonly extensionRoot="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${extensionRoot}"
bash Build/Scripts/buildLibraries.sh
readonly originalLockHash="$(php -r 'echo hash_file("sha256", "Build/SimplePie/composer.lock");')"
bash Build/Scripts/buildLibraries.sh
[[ "${originalLockHash}" == "$(php -r 'echo hash_file("sha256", "Build/SimplePie/composer.lock");')" ]]
export COMPOSER_HOME="${extensionRoot}/.Build/tailor-composer"
composer global require typo3/tailor:1.7.0 --no-interaction --prefer-dist --no-progress
export TYPO3_EXCLUDE_FROM_PACKAGING="${extensionRoot}/Build/Tailor/ExcludeFromPackaging.php"
readonly version="$(php -r '$m = json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR); echo $m["extra"]["typo3/cms"]["version"];')"
php "${COMPOSER_HOME}/vendor/bin/tailor" -n create-artefact "${version}" feed_display --path=.
bash Build/Scripts/verifyTerArtefact.sh "tailor-version-artefact/feed_display_${version}.zip" "${extensionRoot}"
php Build/Scripts/testLibraryPackaging.php "tailor-version-artefact/feed_display_${version}.zip"
