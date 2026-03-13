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

readonly archiveEntries="$(unzip -Z1 "${ARTEFACT_PATH}")"

missingEntries=()
for requiredEntry in "${requiredEntries[@]}"; do
    if ! printf '%s\n' "${archiveEntries}" | grep -Fxq "${requiredEntry}"; then
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
    if printf '%s\n' "${archiveEntries}" | grep -Eq "^${forbiddenPrefix//\//\\/}"; then
        unexpectedEntries+=("${forbiddenPrefix}")
    fi
done

if [[ ${#unexpectedEntries[@]} -gt 0 ]]; then
    printf 'TER artefact still contains excluded paths:\n' >&2
    printf ' - %s\n' "${unexpectedEntries[@]}" >&2
    exit 1
fi

printf 'Verified TER artefact contents: %s\n' "${ARTEFACT_PATH}"
