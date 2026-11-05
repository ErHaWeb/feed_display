#!/usr/bin/env bash

set -euo pipefail
readonly sourceRoot="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly fixture="$(mktemp -d "${TMPDIR:-/tmp}/feed-display-backend-XXXXXX")"
trap 'rm -rf -- "${fixture}"' EXIT
mkdir -p "${fixture}/Build/Scripts" "${fixture}/bin"
cp "${sourceRoot}/Build/Scripts/runTests.sh" "${fixture}/Build/Scripts/runTests.sh"
cat > "${fixture}/bin/engine" <<'ENGINE'
#!/usr/bin/env bash
set -eu
backend="$(basename "$0")"
printf '%s %s\n' "${backend}" "$*" >> "${TEST_BACKEND_LOG}"
case "${backend}" in
    php|composer) echo 'Unexpected host PHP/Composer invocation' >&2; exit 99 ;;
esac
case "${1:-}" in
    info)
        if [[ "${backend}" == docker ]]; then ready="${MOCK_DOCKER_READY}"; else ready="${MOCK_PODMAN_READY}"; fi
        if [[ "${ready}" == 1 ]]; then exit 0; fi
        echo "${backend} mock connection refused" >&2
        exit 1
        ;;
    network)
        if [[ "${2:-}" == create && "${MOCK_NETWORK_FAIL}" == 1 ]]; then
            echo 'simulated network allocation failed' >&2
            exit 1
        fi
        ;;
esac
ENGINE
chmod +x "${fixture}/bin/engine"
for command in docker podman php composer; do ln -s engine "${fixture}/bin/${command}"; done
export PATH="${fixture}/bin:${PATH}"
export TEST_BACKEND_LOG="${fixture}/engine.log"
cd "${fixture}"

resetFixture() {
    bash Build/Scripts/runTests.sh -b host -s clean
    : > "${TEST_BACKEND_LOG}"
    export MOCK_DOCKER_READY=1 MOCK_PODMAN_READY=0 MOCK_NETWORK_FAIL=0 FEED_DISPLAY_TEST_BACKEND=''
}

runSuite() {
    local expected="$1"
    shift
    local status=0
    bash Build/Scripts/runTests.sh "$@" > "${fixture}/output.log" 2>&1 || status=$?
    if [[ "${expected}" == success && "${status}" != 0 ]] || [[ "${expected}" == failure && "${status}" == 0 ]]; then
        cat "${fixture}/output.log" "${TEST_BACKEND_LOG}"
        echo "Unexpected result for $*" >&2
        exit 1
    fi
}

assertNoEngineCalls() {
    local status=0
    grep -En -- "$1" "${TEST_BACKEND_LOG}" || status=$?
    if [[ "${status}" != 1 ]]; then
        echo "Expected no engine calls matching: $1" >&2
        exit 1
    fi
}

resetFixture
runSuite success -s libraries
grep -q '^docker run .*bash Build/Scripts/buildLibraries.sh' "${TEST_BACKEND_LOG}"
assertNoEngineCalls '^podman | network |--network|^(php|composer) '

resetFixture
export MOCK_PODMAN_READY=1
runSuite success -s libraries
assertNoEngineCalls '^podman '

resetFixture
export MOCK_DOCKER_READY=0 MOCK_PODMAN_READY=1
runSuite success -s libraries
grep -q '^podman run ' "${TEST_BACKEND_LOG}"
assertNoEngineCalls ' network '

resetFixture
runSuite failure -s libraries -b podman
grep -q 'Requested container backend is not reachable: podman' output.log
grep -q 'podman mock connection refused' output.log
[[ ! -e .Build && ! -e .cache ]]
assertNoEngineCalls ' run | network '

resetFixture
export MOCK_DOCKER_READY=0
runSuite failure -s libraries
grep -q 'No reachable Docker or Podman service' output.log
[[ ! -e .Build && ! -e .cache ]]
assertNoEngineCalls ' run | network '

resetFixture
export FEED_DISPLAY_TEST_BACKEND=podman MOCK_PODMAN_READY=1
runSuite success -s libraries
grep -q '^podman run ' "${TEST_BACKEND_LOG}"
: > "${TEST_BACKEND_LOG}"
runSuite success -s libraries -b docker
grep -q '^docker run ' "${TEST_BACKEND_LOG}"
assertNoEngineCalls '^podman '

resetFixture
runSuite success -s functional -d sqlite -b docker
assertNoEngineCalls ' network '

resetFixture
runSuite success -s functional -d mariadb -b docker
[[ "$(grep -c '^docker network create ' "${TEST_BACKEND_LOG}")" == 1 ]]
grep -q '^docker run .*--network feed-display-' "${TEST_BACKEND_LOG}"
grep -q '^docker network rm feed-display-' "${TEST_BACKEND_LOG}"

resetFixture
export MOCK_NETWORK_FAIL=1
runSuite failure -s functional -d mariadb -b docker
grep -q 'simulated network allocation failed' output.log
[[ "$(grep -c '^docker network create ' "${TEST_BACKEND_LOG}")" == 1 ]]
assertNoEngineCalls ' network ls| run '
[[ ! -e .Build && ! -e .cache ]]
echo 'Verified reachable-backend selection, Docker preference, explicit overrides, container-only library builds, database-only networks and original error diagnostics.'
