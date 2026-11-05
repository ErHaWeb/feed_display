#!/usr/bin/env bash

set -euo pipefail

readonly extensionRoot="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly bundleProject="${extensionRoot}/Build/SimplePie"
readonly bundleDirectory="${extensionRoot}/Resources/Private/Php/ComposerVendor"

if [[ ! -f "${bundleProject}/composer.lock" ]]; then
    echo 'SimplePie composer.lock is missing. Update the bundle deliberately before building it.' >&2
    exit 1
fi

# Validate the lock before changing the existing runtime bundle.
composer --working-dir="${bundleProject}" validate --strict --no-plugins --no-interaction
php "${extensionRoot}/Build/Scripts/verifyLibraries.php" "${extensionRoot}" "${bundleProject}/composer.lock" --metadata-only

case "${1:-}" in
    '') ;;
    --rebuild)
        if [[ -L "${bundleDirectory}" ]]; then
            echo 'Refusing to remove a symlinked bundle directory.' >&2
            exit 1
        fi
        rm -rf -- "${bundleDirectory}"
        ;;
    *) echo "Usage: $0 [--rebuild]" >&2; exit 1 ;;
esac

composer --working-dir="${bundleProject}" install \
    --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-plugins --no-scripts
php "${extensionRoot}/Build/Scripts/verifyLibraries.php" "${extensionRoot}" "${bundleProject}/composer.lock"
