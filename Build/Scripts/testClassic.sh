#!/usr/bin/env bash

set -euo pipefail
readonly extensionRoot="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${extensionRoot}"
readonly major="${1:?TYPO3 major is required}"
readonly artefact="${2:-}"
readonly setupCommand="Build/Scripts/runTests.sh -s composerInstall -t ${major}"
if [[ ! -f .Build/vendor/autoload.php ]]; then
    echo "Composer test setup is missing. Run ${setupCommand}." >&2
    exit 1
fi

version="$(php -r 'require ".Build/vendor/autoload.php"; echo (new TYPO3\CMS\Core\Information\Typo3Version())->getVersion();')"
if [[ "${version}" != "${major}."* ]]; then
    echo "Composer test setup uses TYPO3 ${version}. Run ${setupCommand}." >&2
    exit 1
fi
mkdir -p .Build/classic-cache
readonly archive="${extensionRoot}/.Build/classic-cache/typo3-${version}.tar.gz"
if [[ ! -f "${archive}" ]]; then
    download="$(mktemp "${archive}.XXXXXX")"
    curl --fail --location --silent --show-error "https://get.typo3.org/${version}" -o "${download}"
    mv "${download}" "${archive}"
fi
serverPid=''
trap 'if [[ -n "${serverPid}" ]]; then kill "${serverPid}" 2>/dev/null || true; fi' EXIT
for mode in linked packaged; do
    if [[ "${mode}" == packaged && -z "${artefact}" ]]; then continue; fi
    instance="$(mktemp -d "${extensionRoot}/.Build/classic-${major}-${mode}-XXXXXX")"
    mkdir -p "${instance}/source" "${instance}/typo3conf/ext" "${instance}/fileadmin" "${instance}/typo3temp"
    tar -xzf "${archive}" -C "${instance}/source" --strip-components=1
    ln -s source "${instance}/typo3_src"
    ln -s typo3_src/typo3 "${instance}/typo3"
    ln -s typo3_src/vendor "${instance}/vendor"
    ln -s typo3_src/index.php "${instance}/index.php"
    touch "${instance}/FIRST_INSTALL"
    (
        cd "${instance}"
        export TYPO3_CONTEXT=Development
        php typo3/sysext/core/bin/typo3 setup --no-interaction --driver=sqlite \
            --admin-username=admin --admin-user-password='Classic-Smoke-Only-5471!' \
            --admin-email=classic@example.test --project-name='Feed Display Classic smoke' --server-type=other
    ) > "${instance}/setup.log" 2>&1 || { cat "${instance}/setup.log"; exit 1; }
    if [[ "${mode}" == linked ]]; then
        ln -s "${extensionRoot}" "${instance}/typo3conf/ext/feed_display"
    else
        mkdir "${instance}/typo3conf/ext/feed_display"
        unzip -q "${artefact}" -d "${instance}/typo3conf/ext/feed_display"
    fi
    (
        cd "${instance}"
        export TYPO3_CONTEXT=Development
        php typo3/sysext/core/bin/typo3 extension:activate fluid_styled_content
        php typo3/sysext/core/bin/typo3 extension:activate feed_display
        php typo3/sysext/core/bin/typo3 extension:setup
    ) > "${instance}/activation.log" 2>&1 || { cat "${instance}/activation.log"; exit 1; }
    port="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo parse_url("tcp://" . stream_socket_get_name($s, false), PHP_URL_PORT);')"
    base="http://127.0.0.1:${port}/"
    TYPO3_CONTEXT=Development php Build/Scripts/classicFixture.php "${instance}" seed "${base}"
    # A fresh PHP process must compile DI without the dev project's SimplePie loader.
    rm -rf -- "${instance}/typo3temp/var/cache"
    TYPO3_CONTEXT=Development php Build/Scripts/classicFixture.php "${instance}" probe
    TYPO3_CONTEXT=Development php -S "127.0.0.1:${port}" -t "${instance}" > "${instance}/server.log" 2>&1 &
    serverPid=$!
    for pass in cold warm; do
        if ! curlOutput="$(curl --fail --silent --show-error --retry 10 --retry-connrefused --retry-delay 1 \
            "${base}" -o "${instance}/${pass}.html" 2>&1)"; then
            printf '%s\n' "${curlOutput}" >&2
            cat "${instance}/server.log" >&2
            exit 1
        fi
        if ! grep -q 'Classic bundle integration item' "${instance}/${pass}.html"; then
            cat "${instance}/${pass}.html"
            cat "${instance}/server.log"
            exit 1
        fi
    done
    kill "${serverPid}"
    wait "${serverPid}" 2>/dev/null || true
    serverPid=''
    (
        cd "${instance}"
        TYPO3_CONTEXT=Development php typo3/sysext/core/bin/typo3 cache:warmup
    )
    echo "Verified TYPO3 ${version} Classic (${mode}), cold/warm frontend and cache warmup: ${instance}"
done
