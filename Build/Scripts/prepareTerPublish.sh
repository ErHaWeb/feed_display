#!/usr/bin/env bash

set -euo pipefail

readonly VERSION="${1:-}"
readonly OUTPUT_FILE="${2:-}"

if [[ -z "${VERSION}" ]]; then
    echo "Usage: $0 <version> [output-file]" >&2
    exit 1
fi

if ! [[ "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Version '${VERSION}' must match x.y.z." >&2
    exit 1
fi

if ! git rev-parse --verify --quiet "refs/tags/${VERSION}^{commit}" >/dev/null; then
    echo "Tag '${VERSION}' does not exist." >&2
    exit 1
fi

readonly TAG_COMMIT="$(git rev-parse "refs/tags/${VERSION}^{commit}")"
readonly CURRENT_COMMIT="$(git rev-parse HEAD)"

if [[ "${CURRENT_COMMIT}" != "${TAG_COMMIT}" ]]; then
    echo "Current checkout must match tag '${VERSION}'." >&2
    exit 1
fi

readonly EXT_EMCONF_VERSION="$(
    php -r '
    $_EXTKEY = "feed_display";
    $EM_CONF = [];
    require "ext_emconf.php";
    echo $EM_CONF[$_EXTKEY]["version"] ?? "";
    '
)"

readonly SETTINGS_VERSION="$(
    sed -nE 's/^version[[:space:]]*=[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+)$/\1/p' Documentation/Settings.cfg
)"

readonly LICENSE_NAME="$(
    php -r '
    $composer = json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR);
    $license = $composer["license"] ?? "";
    echo is_array($license) ? implode(",", $license) : $license;
    '
)"

if [[ "${EXT_EMCONF_VERSION}" != "${VERSION}" ]]; then
    echo "ext_emconf.php version '${EXT_EMCONF_VERSION}' does not match tag '${VERSION}'." >&2
    exit 1
fi

if [[ "${SETTINGS_VERSION}" != "${VERSION}" ]]; then
    echo "Documentation/Settings.cfg version '${SETTINGS_VERSION}' does not match tag '${VERSION}'." >&2
    exit 1
fi

if [[ "${LICENSE_NAME}" != "GPL-2.0-or-later" ]]; then
    echo "composer.json license must be GPL-2.0-or-later." >&2
    exit 1
fi

if [[ ! -f "LICENSE" ]]; then
    echo "LICENSE file is missing." >&2
    exit 1
fi

readonly TAG_PARENT="$(git rev-parse "${TAG_COMMIT}^")"

PREVIOUS_TAG=""
if PREVIOUS_TAG="$(git describe --tags --abbrev=0 --match '[0-9]*.[0-9]*.[0-9]*' "${TAG_PARENT}" 2>/dev/null)"; then
    COMMENT="$(git log --no-merges --pretty=format:%s "${PREVIOUS_TAG}..${TAG_PARENT}")"
else
    COMMENT="$(git log --no-merges --pretty=format:%s "${TAG_PARENT}")"
    PREVIOUS_TAG=""
fi

readonly COMMENT="$(printf '%s\n' "${COMMENT}" | sed '/^[[:space:]]*$/d')"

if [[ -z "${COMMENT}" ]]; then
    echo "Release comment would be empty for tag '${VERSION}'." >&2
    exit 1
fi

if [[ -n "${OUTPUT_FILE}" ]]; then
    {
        printf 'version=%s\n' "${VERSION}"
        printf 'previous_tag=%s\n' "${PREVIOUS_TAG}"
        printf 'comment<<__TER_COMMENT__\n%s\n__TER_COMMENT__\n' "${COMMENT}"
    } >> "${OUTPUT_FILE}"
fi

printf 'Prepared TER release metadata for %s\n' "${VERSION}"
if [[ -n "${PREVIOUS_TAG}" ]]; then
    printf 'Previous tag: %s\n' "${PREVIOUS_TAG}"
else
    printf 'Previous tag: <none>\n'
fi
