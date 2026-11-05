#!/usr/bin/env bash

set -euo pipefail

readonly ARTEFACT_PATH="${1:-}"

if [[ -z "${ARTEFACT_PATH}" ]]; then
    echo "Usage: $0 <artefact-path>" >&2
    exit 1
fi

if [[ ! -f "${ARTEFACT_PATH}" ]]; then
    echo "TER artefact '${ARTEFACT_PATH}' does not exist." >&2
    exit 1
fi

requiredEntries=(
    "composer.json"
    "ext_emconf.php"
    "ext_localconf.php"
    "Configuration/FlexForms/FeedDisplay.xml"
    "Configuration/Services.yaml"
    "Configuration/Sets/FeedDisplay/config.yaml"
    "Configuration/TypoScript/constants.typoscript"
    "Configuration/TypoScript/setup.typoscript"
    "Resources/Private/Templates/Feed/Display.html"
    "Resources/Public/Icons/Extension.svg"
)

forbiddenPrefixes=(
    ".Build/"
    ".composer/"
    ".github/"
    "Build/"
    "Tests/"
    "tailor-version-artefact/"
)

archiveEntries="$(php -r '$zip = new ZipArchive(); if ($zip->open($argv[1]) !== true) { exit(1); } for ($i = 0; $i < $zip->numFiles; ++$i) { echo $zip->getNameIndex($i), PHP_EOL; }' "${ARTEFACT_PATH}")"
readonly archiveEntries

missingEntries=()
for requiredEntry in "${requiredEntries[@]}"; do
    if ! grep -Fxq "${requiredEntry}" <<< "${archiveEntries}"; then
        missingEntries+=("${requiredEntry}")
    fi
done

if [[ ${#missingEntries[@]} -gt 0 ]]; then
    printf 'TER artefact is missing required runtime paths:\n' >&2
    printf ' - %s\n' "${missingEntries[@]}" >&2
    exit 1
fi

unexpectedEntries=()
for forbiddenPrefix in "${forbiddenPrefixes[@]}"; do
    while IFS= read -r archiveEntry; do
        if [[ "${archiveEntry}" == "${forbiddenPrefix}"* ]]; then
            unexpectedEntries+=("${forbiddenPrefix}")
            break
        fi
    done <<< "${archiveEntries}"
done

if [[ ${#unexpectedEntries[@]} -gt 0 ]]; then
    printf 'TER artefact still contains excluded paths:\n' >&2
    printf ' - %s\n' "${unexpectedEntries[@]}" >&2
    exit 1
fi

php "$(dirname "${BASH_SOURCE[0]}")/verifyTerArtefact.php" "${ARTEFACT_PATH}" "${2:-.}"
printf 'Verified TER artefact contents: %s\n' "${ARTEFACT_PATH}"
