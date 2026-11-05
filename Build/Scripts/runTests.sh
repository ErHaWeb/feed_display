#!/usr/bin/env bash

#
# Package-local TYPO3 extension test runner for feed_display.
#

set -eu -o pipefail

cleanUp() {
    if [ -n "${CONTAINER_BIN:-}" ] && [ "${CONTAINER_BIN}" != "host" ] && [ -n "${NETWORK:-}" ]; then
        ATTACHED_CONTAINERS=$(${CONTAINER_BIN} ps -a --filter network=${NETWORK} --format='{{.Names}}' 2>/dev/null || true)
        for ATTACHED_CONTAINER in ${ATTACHED_CONTAINERS}; do
            ${CONTAINER_BIN} rm -f "${ATTACHED_CONTAINER}" >/dev/null 2>&1 || true
        done
        ${CONTAINER_BIN} network rm "${NETWORK}" >/dev/null 2>&1 || true
    fi
}

onExit() {
    local EXIT_CODE=$?
    trap - EXIT

    cleanUp || true
    exit "${EXIT_CODE}"
}

trap onExit EXIT
trap 'exit 2' INT TERM

waitFor() {
    local HOST=${1}
    local PORT=${2}
    local CONTAINER_NAME=${3}
    local TESTCOMMAND="
        COUNT=0;
        while ! nc -z ${HOST} ${PORT}; do
            if [ \"\${COUNT}\" -gt 60 ]; then
                echo \"Can not connect to ${HOST} port ${PORT}. Aborting.\" >&2;
                exit 1;
            fi;
            sleep 1;
            COUNT=\$((COUNT + 1));
        done;
    "

    echo "Waiting for ${HOST}:${PORT} ..."
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name "${CONTAINER_NAME}" ${IMAGE_PHP} /bin/sh -c "${TESTCOMMAND}"
}

logContainerLogs() {
    local CONTAINER_NAME=${1}

    if [ -z "${CONTAINER_BIN:-}" ] || [ "${CONTAINER_BIN}" = "host" ]; then
        return
    fi

    echo "---- Logs for ${CONTAINER_NAME} ----" >&2
    ${CONTAINER_BIN} logs "${CONTAINER_NAME}" >&2 || true
    echo "---- End logs for ${CONTAINER_NAME} ----" >&2
}


cleanContainers() {
    local BIN=""
    local NAME=""

    for BIN in docker podman; do
        command -v "${BIN}" >/dev/null 2>&1 || continue

        while IFS= read -r NAME; do
            [ -n "${NAME}" ] || continue
            ${BIN} rm -f "${NAME}" >/dev/null 2>&1 || true
        done < <(
            ${BIN} ps -a --format '{{.Names}}' 2>/dev/null                 | grep -E '^(mariadb-func|mysql-func|postgres-func|wait-(mariadb|mysql|postgres))-[0-9]+-[0-9]+$' || true
        )

        while IFS= read -r NAME; do
            [ -n "${NAME}" ] || continue
            ${BIN} network rm "${NAME}" >/dev/null 2>&1 || true
        done < <(
            ${BIN} network ls --format '{{.Name}}' 2>/dev/null                 | grep -E '^feed-display-[0-9]+-[0-9]+$' || true
        )
    done
}

createContainerNetwork() {
    local NETWORK_ERROR=""
    if NETWORK_ERROR=$(${CONTAINER_BIN} network create "${NETWORK}" 2>&1); then
        return 0
    fi
    echo "Could not create container network ${NETWORK} (${CONTAINER_BIN})." >&2
    printf '%s\n' "${NETWORK_ERROR}" >&2
    return 1
}

selectContainerBackend() {
    local CANDIDATE=""
    local DETAILS=""
    local ERRORS=""
    if [ -z "${CONTAINER_BIN}" ]; then
        # An installed CLI alone does not mean its service/VM is running.
        for CANDIDATE in docker podman; do
            command -v "${CANDIDATE}" >/dev/null 2>&1 || continue
            if DETAILS=$("${CANDIDATE}" info 2>&1); then
                CONTAINER_BIN="${CANDIDATE}"
                return 0
            fi
            ERRORS="${ERRORS}${CANDIDATE}: ${DETAILS}"$'\n'
        done
        echo 'No reachable Docker or Podman service. Start Docker/OrbStack or your Podman machine.' >&2
        if [ -n "${ERRORS}" ]; then printf '%s' "${ERRORS}" >&2; fi
        return 1
    fi
    if [ "${CONTAINER_BIN}" = host ]; then return 0; fi
    if ! command -v "${CONTAINER_BIN}" >/dev/null 2>&1; then
        echo "Requested container backend not found: ${CONTAINER_BIN}" >&2
        return 1
    fi
    if ! DETAILS=$("${CONTAINER_BIN}" info 2>&1); then
        echo "Requested container backend is not reachable: ${CONTAINER_BIN}" >&2
        printf '%s\n' "${DETAILS}" >&2
        echo 'Start that service/VM or select another backend with -b docker|podman.' >&2
        return 1
    fi
}

startDbContainer() {
    local CONTAINER_NAME=${1}
    shift

    echo "Starting database container ${CONTAINER_NAME} ..."
    if ! ${CONTAINER_BIN} run --rm --name "${CONTAINER_NAME}" --network "${NETWORK}" -d "$@" >/dev/null; then
        echo "Could not start database container ${CONTAINER_NAME}" >&2
        logContainerLogs "${CONTAINER_NAME}"
        exit 1
    fi
}

waitForOrDumpLogs() {
    local HOST=${1}
    local PORT=${2}
    local WAIT_CONTAINER_NAME=${3}
    local DB_CONTAINER_NAME=${4}

    if ! waitFor "${HOST}" "${PORT}" "${WAIT_CONTAINER_NAME}"; then
        logContainerLogs "${DB_CONTAINER_NAME}"
        exit 1
    fi
}

handleDbmsOptions() {
    case ${DBMS} in
        mariadb)
            if [ -z "${DATABASE_DRIVER}" ]; then
                DATABASE_DRIVER="mysqli"
            fi
            if [ "${DATABASE_DRIVER}" != "mysqli" ] && [ "${DATABASE_DRIVER}" != "pdo_mysql" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                exit 1
            fi
            if [ -z "${DBMS_VERSION}" ]; then
                DBMS_VERSION="10.11"
            fi
            ;;
        mysql)
            if [ -z "${DATABASE_DRIVER}" ]; then
                DATABASE_DRIVER="mysqli"
            fi
            if [ "${DATABASE_DRIVER}" != "mysqli" ] && [ "${DATABASE_DRIVER}" != "pdo_mysql" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                exit 1
            fi
            if [ -z "${DBMS_VERSION}" ]; then
                DBMS_VERSION="8.0"
            fi
            ;;
        postgres)
            if [ -n "${DATABASE_DRIVER}" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                exit 1
            fi
            if [ -z "${DBMS_VERSION}" ]; then
                DBMS_VERSION="16"
            fi
            ;;
        sqlite)
            if [ -n "${DATABASE_DRIVER}" ] || [ -n "${DBMS_VERSION}" ]; then
                echo "Invalid sqlite combination" >&2
                exit 1
            fi
            ;;
        *)
            echo "Invalid option -d ${DBMS}" >&2
            exit 1
            ;;
    esac

    return 0
}

cleanBuildArtifacts() {
    rm -rf -- .Build composer.lock composer.json.orig composer.json.testing
}

cleanAllArtifacts() {
    cleanBuildArtifacts
    # Explicit generated paths only: never use git clean or delete Build/ sources.
    rm -rf -- .cache .composer var config tailor-version-artefact \
        Documentation-GENERATED-temp .phpunit.cache .phpunit.result.cache .php-cs-fixer.cache \
        Tests/Build/.phpunit.result.cache playwright-report test-results playwright/.auth \
        Build/rector/composer.lock Build/fractor/composer.lock \
        Resources/Private/Php/ComposerVendor
    # Remove empty generated parents; preserve any sources/baselines inside them.
    local DIRECTORY=""
    for DIRECTORY in playwright Tests/Build Resources/Private/Php Libraries; do
        if [ -d "${DIRECTORY}" ] && [ ! -L "${DIRECTORY}" ]; then
            rmdir -- "${DIRECTORY}" 2>/dev/null || true
        fi
    done
}

loadHelp() {
    cat <<EOF
Package-local TYPO3 extension test runner for feed_display.

Usage: Build/Scripts/runTests.sh [options] [-- extra-args]

Options:
    -s <composer|composerInstall|composerValidate|libraries|verifyLibraries|classic|artefact|lintPhp|lintJson|lintYaml|lintServicesYaml|lintTypoScript|lintXliff|cgl|phpstan|phpstanGenerateBaseline|coverageUnit|coverageFunctional|phpmd|unit|unitRandom|functional|rector|fractor|clean|cleanBuild|cleanCache|cleanTests|cleanContainers|testCleanup|testBackend>
        Specifies which suite to run

        cleanBuild: remove .Build and temporary root Composer files
        clean: additionally remove caches, test config/runtime, TER archives,
               generated SimplePie bundle, tool lockfiles and browser/doc outputs
               Classic checkouts need composer build-extension afterwards
        testCleanup: verify cleanup in a disposable fixture without dependencies
        testBackend: verify backend selection and container networking in fixtures

    -t <13|14>
        TYPO3 major to use for composerInstall
            - 13: use TYPO3 13.4
            - 14: use TYPO3 14.3 (default)

    -p <8.2|8.3|8.4|8.5>
        PHP minor version to use
            - 8.2 (default)
            - 8.3
            - 8.4
            - 8.5

    -d <sqlite|mariadb|mysql|postgres>
        Only with -s functional|coverageFunctional
        Database engine for functional test suites
            - sqlite (default)
            - mariadb
            - mysql
            - postgres

    -a <mysqli|pdo_mysql>
        Only with -s functional|coverageFunctional and -d mariadb|mysql

    -i <version>
        Optional database version for mariadb/mysql/postgres
        Only with -s functional|coverageFunctional

    -b <host|docker|podman>
        Execution backend
        Default: first reachable service, preferring Docker over Podman
        FEED_DISPLAY_TEST_BACKEND sets a default; -b overrides it
        libraries runs PHP/Composer in the container unless -b host is explicit

    -e "<tool options>"
        Pass-through options for the underlying tool or test runner

    -n
        Only with -s rector|fractor
        Activate dry-run mode

    -x
        Enable xdebug

    -y <port>
        Xdebug port, default 9003

    -h
        Show this help

Examples:
    Build/Scripts/runTests.sh -s composerInstall
    Build/Scripts/runTests.sh -s composerValidate
    Build/Scripts/runTests.sh -s libraries
    Build/Scripts/runTests.sh -s verifyLibraries
    Build/Scripts/runTests.sh -s classic -t 14
    Build/Scripts/runTests.sh -s artefact
    Build/Scripts/runTests.sh -s lintPhp
    Build/Scripts/runTests.sh -s lintJson
    Build/Scripts/runTests.sh -s lintYaml
    Build/Scripts/runTests.sh -s lintServicesYaml
    Build/Scripts/runTests.sh -s lintTypoScript
    Build/Scripts/runTests.sh -s lintXliff
    Build/Scripts/runTests.sh -s cgl
    Build/Scripts/runTests.sh -s phpstan
    Build/Scripts/runTests.sh -s phpstanGenerateBaseline
    Build/Scripts/runTests.sh -s unit
    Build/Scripts/runTests.sh -s unitRandom
    Build/Scripts/runTests.sh -s functional -d postgres
    Build/Scripts/runTests.sh -s coverageUnit
    Build/Scripts/runTests.sh -s coverageFunctional -d mariadb -a mysqli
    Build/Scripts/runTests.sh -s phpmd
    Build/Scripts/runTests.sh -s rector -n
    Build/Scripts/runTests.sh -s fractor -n
    Build/Scripts/runTests.sh -s cleanBuild
    Build/Scripts/runTests.sh -s clean
    Build/Scripts/runTests.sh -s testCleanup -b host
    Build/Scripts/runTests.sh -s testBackend -b host
EOF
}

runPhpCommand() {
    local CONTAINER_NAME=${1}
    shift
    local COMMAND=("$@")
    if [ "${CONTAINER_BIN}" = "host" ]; then
        COMPOSER_ROOT_VERSION="${COMPOSER_ROOT_VERSION:-14.0.0-dev}" "${COMMAND[@]}"
        return
    fi
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name "${CONTAINER_NAME}" ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" -e COMPOSER_ROOT_VERSION="${COMPOSER_ROOT_VERSION:-14.0.0-dev}" ${EXTRA_ENV_PARAMS:-} ${IMAGE_PHP} "${COMMAND[@]}"
}

runPhpShellCommand() {
    local CONTAINER_NAME=${1}
    local COMMAND=${2}
    if [ "${CONTAINER_BIN}" = "host" ]; then
        local ENV_EXPORTS="export COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION:-14.0.0-dev};"
        local HOST_COMMAND=${COMMAND}
        if [ ${PHP_XDEBUG_ON} -eq 0 ]; then
            ENV_EXPORTS="${ENV_EXPORTS} export XDEBUG_MODE=off;"
        else
            ENV_EXPORTS="${ENV_EXPORTS} export XDEBUG_MODE=debug XDEBUG_TRIGGER=feed_display XDEBUG_CONFIG=client_port=${PHP_XDEBUG_PORT};"
        fi
        if [ -n "${EXTRA_ENV_PARAMS:-}" ]; then
            ENV_EXPORTS="${ENV_EXPORTS} export ${EXTRA_ENV_PARAMS};"
        fi
        case "${HOST_COMMAND}" in
            .Build/bin/phpunit*|.Build/bin/phpstan*|.Build/bin/php-cs-fixer*)
                HOST_COMMAND="php -d memory_limit=${HOST_PHP_MEMORY_LIMIT:-1G} ${HOST_COMMAND}"
                ;;
        esac
        bash -lc "${ENV_EXPORTS} ${HOST_COMMAND}"
        return
    fi
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name "${CONTAINER_NAME}" ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" -e COMPOSER_ROOT_VERSION="${COMPOSER_ROOT_VERSION:-14.0.0-dev}" ${EXTRA_ENV_PARAMS:-} ${IMAGE_PHP} /bin/sh -lc "${COMMAND}"
}

runComposerInstallInWorkingDir() {
    local WORKING_DIR="$1"

    if [ ! -f "${WORKING_DIR}/composer.json" ]; then
        echo "Missing composer.json in ${WORKING_DIR}" >&2
        exit 1
    fi

    runPhpShellCommand \
        "composer-install-$(basename "${WORKING_DIR}")-${SUFFIX}" \
        "composer --working-dir=${WORKING_DIR} install"
}

hostCoverageDriverAvailable() {
    php -r 'exit((int)!(extension_loaded("xdebug") || extension_loaded("pcov")));'
}

ensureHostCoverageDriver() {
    if [ "${CONTAINER_BIN}" = "host" ] && ! hostCoverageDriverAvailable; then
        echo "Coverage requires Xdebug or PCOV in the active host runtime. phpdbg alone is not sufficient for this PHPUnit/php-code-coverage stack." >&2
        exit 1
    fi
}

refreshHostFractorExtensionPaths() {
    if [ "${CONTAINER_BIN}" != "host" ]; then
        return
    fi

    local GENERATED_FILE=".Build/fractor/vendor/a9f/fractor-extension-installer/generated/InstalledPackages.php"
    if [ ! -f "${GENERATED_FILE}" ]; then
        return
    fi

    php <<'PHP'
<?php
declare(strict_types=1);

require '.Build/vendor/autoload.php';

$generatedFile = '.Build/fractor/vendor/a9f/fractor-extension-installer/generated/InstalledPackages.php';
$packages = [];

foreach (array_keys(\a9f\FractorExtensionInstaller\Generated\InstalledPackages::PACKAGES) as $packageName) {
    $path = getcwd() . '/.Build/vendor/' . $packageName;
    if (!is_dir($path)) {
        continue;
    }

    $packages[$packageName] = ['path' => $path];
}

\a9f\FractorExtensionInstaller\PackagesFileGenerator::write($packages, $generatedFile);
PHP
}

THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
cd "${THIS_SCRIPT_DIR}/../../" || exit 1
ROOT_DIR="${PWD}"

TEST_SUITE=""
TYPO3_VERSION="14"
DBMS="sqlite"
DBMS_VERSION=""
DATABASE_DRIVER=""
PHP_VERSION="8.2"
PHP_XDEBUG_ON=0
PHP_XDEBUG_PORT=9003
EXTRA_TEST_OPTIONS=""
DRY_RUN=0
CONTAINER_BIN="${FEED_DISPLAY_TEST_BACKEND:-}"
CONTAINER_INTERACTIVE="--init"
HOST_UID=$(id -u)
HOST_GID=$(id -g)
SUFFIX="${RANDOM}-$$"
NETWORK=""
CONTAINER_HOST="host.docker.internal"
USERSET=""

while getopts "a:b:s:d:i:p:e:t:nxy:h" OPT; do
    case ${OPT} in
        s) TEST_SUITE=${OPTARG} ;;
        a) DATABASE_DRIVER=${OPTARG} ;;
        b) CONTAINER_BIN=${OPTARG} ;;
        d) DBMS=${OPTARG} ;;
        i) DBMS_VERSION=${OPTARG} ;;
        p) PHP_VERSION=${OPTARG} ;;
        e) EXTRA_TEST_OPTIONS=${OPTARG} ;;
        t) TYPO3_VERSION=${OPTARG} ;;
        n) DRY_RUN=1 ;;
        x) PHP_XDEBUG_ON=1 ;;
        y) PHP_XDEBUG_PORT=${OPTARG} ;;
        h)
            loadHelp
            exit 0
            ;;
        *)
            loadHelp >&2
            exit 1
            ;;
    esac
done
shift $((OPTIND - 1))

if ! [[ ${TYPO3_VERSION} =~ ^(13|14)$ ]]; then
    echo "Unsupported TYPO3 major: ${TYPO3_VERSION}" >&2
    exit 1
fi

if [ -n "${CONTAINER_BIN}" ] && ! [[ ${CONTAINER_BIN} =~ ^(host|docker|podman)$ ]]; then
    echo "Unsupported container backend: ${CONTAINER_BIN}" >&2
    exit 1
fi

if ! [[ ${PHP_VERSION} =~ ^(8.2|8.3|8.4|8.5)$ ]]; then
    echo "Unsupported PHP version: ${PHP_VERSION}" >&2
    exit 1
fi

handleDbmsOptions

case ${TEST_SUITE} in
    cleanBuild)
        cleanBuildArtifacts
        exit 0
        ;;
    cleanCache)
        rm -rf .cache .phpunit.cache
        exit 0
        ;;
    cleanTests)
        rm -rf .Build/coverage playwright-report test-results playwright/.auth .phpunit.cache
        exit 0
        ;;
    cleanContainers)
        cleanContainers
        exit 0
        ;;
    clean)
        cleanAllArtifacts
        exit 0
        ;;
    testCleanup)
        bash Build/Scripts/testCleanup.sh
        exit 0
        ;;
    testBackend)
        bash Build/Scripts/testBackend.sh
        exit 0
        ;;
esac

selectContainerBackend || exit 1
echo "Execution backend: ${CONTAINER_BIN}"

if [ "$(uname)" != "Darwin" ] && [ "${CONTAINER_BIN}" = "docker" ]; then
    USERSET="--user ${HOST_UID}:${HOST_GID}"
fi

if [ -t 0 ] && [ -t 1 ]; then
    CONTAINER_INTERACTIVE="-it --init"
fi

IMAGE_PHP="ghcr.io/typo3/core-testing-php${PHP_VERSION//./}:latest"
IMAGE_MARIADB="docker.io/mariadb:${DBMS_VERSION}"
IMAGE_MYSQL="docker.io/mysql:${DBMS_VERSION}"
IMAGE_POSTGRES="docker.io/postgres:${DBMS_VERSION}-alpine"
COMPOSER_ROOT_VERSION="${TYPO3_VERSION}.0.0-dev"

if [ "${CONTAINER_BIN}" != "host" ]; then
    CONTAINER_NETWORK_PARAMS=""
    # Only database containers need to share a dedicated network. Single-container
    # builds (including libraries) use the engine's normal container networking.
    case ${TEST_SUITE} in
        functional|coverageFunctional)
            if [ "${DBMS}" != sqlite ]; then
                NETWORK="feed-display-${SUFFIX}"
                createContainerNetwork || exit 1
                CONTAINER_NETWORK_PARAMS="--network ${NETWORK}"
            fi
            ;;
    esac

    if [ "${CONTAINER_BIN}" = "docker" ]; then
        CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm ${CONTAINER_NETWORK_PARAMS} --add-host ${CONTAINER_HOST}:host-gateway ${USERSET} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
    else
        CONTAINER_HOST="host.containers.internal"
        CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm ${CONTAINER_NETWORK_PARAMS} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
    fi
fi

mkdir -p .cache .Build/Web/typo3temp/var/tests .Build/coverage

if [ ${PHP_XDEBUG_ON} -eq 0 ]; then
    XDEBUG_MODE="-e XDEBUG_MODE=off"
    XDEBUG_CONFIG="client_host=${CONTAINER_HOST} client_port=${PHP_XDEBUG_PORT}"
else
    XDEBUG_MODE="-e XDEBUG_MODE=debug -e XDEBUG_TRIGGER=feed_display"
    XDEBUG_CONFIG="client_host=${CONTAINER_HOST} client_port=${PHP_XDEBUG_PORT}"
fi

SUITE_EXIT_CODE=0
EXTRA_ENV_PARAMS=""

case ${TEST_SUITE} in
    composer)
        runPhpCommand "composer-command-${SUFFIX}" composer "$@"
        ;;
    composerInstall)
        rm -rf -- .Build composer.lock
        case ${TYPO3_VERSION} in
            13) TYPO3_CONSTRAINT='^13.4' ;;
            14) TYPO3_CONSTRAINT='^14.3' ;;
        esac
        TYPO3_CONSTRAINT_OPTIONS=()
        for PACKAGE in backend core extbase fluid frontend fluid-styled-content install; do
            TYPO3_CONSTRAINT_OPTIONS+=(--with "typo3/cms-${PACKAGE}:${TYPO3_CONSTRAINT}")
        done
        runPhpCommand "composer-install-${SUFFIX}" composer update --no-ansi --no-interaction --no-progress "${TYPO3_CONSTRAINT_OPTIONS[@]}" "$@"
        if [ "${TYPO3_VERSION}" = "13" ]; then
            # TYPO3 13 functional tests scan the bundle's Classic classmap.
            runPhpCommand "libraries-${SUFFIX}" bash Build/Scripts/buildLibraries.sh
        fi
        ;;
    libraries)
        runPhpCommand "libraries-${SUFFIX}" bash Build/Scripts/buildLibraries.sh "$@"
        ;;
    verifyLibraries)
        runPhpCommand "verify-libraries-${SUFFIX}" php Build/Scripts/verifyLibraries.php "$@"
        ;;
    classic)
        runPhpCommand "classic-${SUFFIX}" bash Build/Scripts/testClassic.sh "${TYPO3_VERSION}" "$@"
        ;;
    artefact)
        runPhpCommand "artefact-${SUFFIX}" bash Build/Scripts/testTerArtefact.sh "$@"
        ;;
    composerValidate)
        runPhpCommand "composer-validate-${SUFFIX}" composer validate --no-check-lock "$@"
        ;;
    lintPhp)
        runPhpShellCommand "lint-php-${SUFFIX}" ".Build/bin/parallel-lint --exclude .Build --exclude .cache --exclude Resources/Private/Php/ComposerVendor . Build Classes Configuration Tests"
        ;;
    lintJson)
        runPhpShellCommand "lint-json-${SUFFIX}" "find . ! -path '*/.cache/*' ! -path '*/.Build/*' ! -path '*/node_modules/*' ! -path '*/Resources/Private/Php/ComposerVendor/*' -name '*.json' -print0 | xargs -0 -r .Build/bin/jsonlint -q"
        ;;
    lintYaml)
        runPhpShellCommand "lint-yaml-${SUFFIX}" "find . ! -path '*/.cache/*' ! -path '*/.Build/*' ! -path '*/node_modules/*' ! -path '*/Resources/Private/Php/ComposerVendor/*' \\( -name '*.yaml' -o -name '*.yml' \\) -print0 | xargs -0 -r .Build/bin/yaml-lint"
        ;;
    lintServicesYaml)
        runPhpShellCommand "lint-services-yaml-${SUFFIX}" "find . ! -path '*/.cache/*' ! -path '*/.Build/*' ! -path '*/node_modules/*' ! -path '*/Resources/Private/Php/ComposerVendor/*' \\( -name 'Services.yaml' -o -name 'Services.yml' \\) -print0 | xargs -0 -r .Build/bin/yaml-lint"
        ;;
    lintTypoScript)
        runPhpShellCommand "lint-typoscript-${SUFFIX}" "find Configuration Tests -type f \\( -name '*.typoscript' -o -name '*.tsconfig' \\) ! -path '*/.Build/*' ! -path '*/node_modules/*' ! -path '*/Resources/Private/Php/ComposerVendor/*' -print0 2>/dev/null | xargs -0 -r .Build/bin/typoscript-lint -c Build/typoscript-lint/config.yml --ansi -n --fail-on-warnings -vvv"
        ;;
    lintXliff)
        runPhpShellCommand "lint-xliff-${SUFFIX}" "find Configuration ContentBlocks Resources Tests -type f \\( -name '*.xlf' -o -name '*.xliff' \\) ! -path '*/.Build/*' ! -path '*/node_modules/*' ! -path '*/Resources/Private/Php/ComposerVendor/*' -print0 2>/dev/null | xargs -0 -r php Build/Scripts/xliffLint.sh lint:xliff"
        ;;
    cgl)
        PHP_CS_FIXER_FLAGS=""
        [ "${CONTAINER_BIN}" = "host" ] && PHP_CS_FIXER_FLAGS="--sequential"
        runPhpShellCommand "cgl-${SUFFIX}" ".Build/bin/php-cs-fixer fix --config=Build/php-cs-fixer/config.php --dry-run --diff -v ${PHP_CS_FIXER_FLAGS} ${EXTRA_TEST_OPTIONS} $*"
        ;;
    phpstan)
        PHPSTAN_FLAGS=""
        PHPSTAN_MEMORY_LIMIT=""
        [ "${CONTAINER_BIN}" = "host" ] && PHPSTAN_FLAGS="--debug"
        [ "${CONTAINER_BIN}" = "host" ] && PHPSTAN_MEMORY_LIMIT="--memory-limit=${HOST_PHP_MEMORY_LIMIT:-1G}"
        runPhpShellCommand "phpstan-${SUFFIX}" ".Build/bin/phpstan analyse --no-progress ${PHPSTAN_FLAGS} ${PHPSTAN_MEMORY_LIMIT} --configuration=Build/phpstan/phpstan.neon ${EXTRA_TEST_OPTIONS} $*"
        ;;
    phpstanGenerateBaseline)
        PHPSTAN_FLAGS=""
        PHPSTAN_MEMORY_LIMIT=""
        [ "${CONTAINER_BIN}" = "host" ] && PHPSTAN_FLAGS="--debug"
        [ "${CONTAINER_BIN}" = "host" ] && PHPSTAN_MEMORY_LIMIT="--memory-limit=${HOST_PHP_MEMORY_LIMIT:-1G}"
        runPhpShellCommand "phpstan-generate-baseline-${SUFFIX}" ".Build/bin/phpstan analyse --no-progress ${PHPSTAN_FLAGS} ${PHPSTAN_MEMORY_LIMIT} --configuration=Build/phpstan/phpstan.neon --generate-baseline=Build/phpstan/phpstan-baseline.neon --allow-empty-baseline ${EXTRA_TEST_OPTIONS} $*"
        ;;
    coverageUnit)
        ensureHostCoverageDriver
        runPhpShellCommand "coverage-unit-${SUFFIX}" "mkdir -p .Build/Web/typo3temp/var/tests .Build/coverage && XDEBUG_MODE=coverage .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --coverage-clover .Build/coverage/unit.xml ${EXTRA_TEST_OPTIONS} $*"
        ;;
    coverageFunctional)
        COVERAGE_HOST_ENV_PREFIX=""
        if [ "${CONTAINER_BIN}" = "host" ] && [ "${DBMS}" != "sqlite" ]; then
            echo "Host backend supports only sqlite for functional tests" >&2
            exit 1
        fi
        case ${DBMS} in
            mariadb)
                startDbContainer "mariadb-func-${SUFFIX}" -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MARIADB}
                waitForOrDumpLogs "mariadb-func-${SUFFIX}" 3306 "wait-mariadb-${SUFFIX}" "mariadb-func-${SUFFIX}"
                EXTRA_ENV_PARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mariadb-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ;;
            mysql)
                startDbContainer "mysql-func-${SUFFIX}" -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MYSQL}
                waitForOrDumpLogs "mysql-func-${SUFFIX}" 3306 "wait-mysql-${SUFFIX}" "mysql-func-${SUFFIX}"
                EXTRA_ENV_PARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mysql-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ;;
            postgres)
                startDbContainer "postgres-func-${SUFFIX}" -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=funcu --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid ${IMAGE_POSTGRES}
                waitForOrDumpLogs "postgres-func-${SUFFIX}" 5432 "wait-postgres-${SUFFIX}" "postgres-func-${SUFFIX}"
                EXTRA_ENV_PARAMS="-e typo3DatabaseDriver=pdo_pgsql -e typo3DatabaseName=func_test -e typo3DatabaseUsername=funcu -e typo3DatabaseHost=postgres-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ;;
            sqlite)
                mkdir -p "${ROOT_DIR}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs"
                if [ "${CONTAINER_BIN}" = "host" ]; then
                    EXTRA_ENV_PARAMS="typo3DatabaseDriver=pdo_sqlite"
                    COVERAGE_HOST_ENV_PREFIX="typo3DatabaseDriver=pdo_sqlite "
                else
                    EXTRA_ENV_PARAMS="-e typo3DatabaseDriver=pdo_sqlite"
                fi
                ;;
        esac
        ensureHostCoverageDriver
        runPhpShellCommand "coverage-functional-${SUFFIX}" "mkdir -p .Build/Web/typo3temp/var/tests .Build/coverage && ${COVERAGE_HOST_ENV_PREFIX}XDEBUG_MODE=coverage .Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --coverage-clover .Build/coverage/functional.xml ${EXTRA_TEST_OPTIONS} $*"
        ;;
    phpmd)
        runPhpShellCommand "phpmd-${SUFFIX}" "if [ -d Classes ] || [ -d Configuration ]; then php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' .Build/bin/phpmd Classes,Configuration ansi codesize,design,naming,unusedcode --exclude '*/Fixtures/*,*/Tests/*'; else echo 'No PHP directories for PHPMD'; fi"
        ;;
    unit)
        runPhpShellCommand "unit-${SUFFIX}" ".Build/bin/phpunit -c Build/phpunit/UnitTests.xml ${EXTRA_TEST_OPTIONS} $*"
        ;;
    unitRandom)
        runPhpShellCommand "unit-random-${SUFFIX}" ".Build/bin/phpunit -c Build/phpunit/UnitTests.xml --order-by=random ${EXTRA_TEST_OPTIONS} $*"
        ;;
    functional)
        if [ "${CONTAINER_BIN}" = "host" ] && [ "${DBMS}" != "sqlite" ]; then
            echo "Host backend supports only sqlite for functional tests" >&2
            exit 1
        fi
        case ${DBMS} in
            mariadb)
                startDbContainer "mariadb-func-${SUFFIX}" -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MARIADB}
                waitForOrDumpLogs "mariadb-func-${SUFFIX}" 3306 "wait-mariadb-${SUFFIX}" "mariadb-func-${SUFFIX}"
                EXTRA_ENV_PARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mariadb-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ;;
            mysql)
                startDbContainer "mysql-func-${SUFFIX}" -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MYSQL}
                waitForOrDumpLogs "mysql-func-${SUFFIX}" 3306 "wait-mysql-${SUFFIX}" "mysql-func-${SUFFIX}"
                EXTRA_ENV_PARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mysql-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ;;
            postgres)
                startDbContainer "postgres-func-${SUFFIX}" -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=funcu --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid ${IMAGE_POSTGRES}
                waitForOrDumpLogs "postgres-func-${SUFFIX}" 5432 "wait-postgres-${SUFFIX}" "postgres-func-${SUFFIX}"
                EXTRA_ENV_PARAMS="-e typo3DatabaseDriver=pdo_pgsql -e typo3DatabaseName=func_test -e typo3DatabaseUsername=funcu -e typo3DatabaseHost=postgres-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ;;
            sqlite)
                mkdir -p "${ROOT_DIR}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs"
                if [ "${CONTAINER_BIN}" = "host" ]; then
                    EXTRA_ENV_PARAMS="typo3DatabaseDriver=pdo_sqlite"
                else
                    EXTRA_ENV_PARAMS="-e typo3DatabaseDriver=pdo_sqlite"
                fi
                ;;
        esac
        runPhpShellCommand "functional-${SUFFIX}" ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml ${EXTRA_TEST_OPTIONS} $*"
        ;;
    rector)
        RECTOR_DRY_RUN=""
        [ ${DRY_RUN} -eq 1 ] && RECTOR_DRY_RUN="--dry-run"

        runComposerInstallInWorkingDir "Build/rector"

        runPhpShellCommand \
            "rector-${SUFFIX}" \
            ".Build/rector/bin/rector process --config=Build/rector/rector.php --no-progress-bar --ansi ${RECTOR_DRY_RUN} ${EXTRA_TEST_OPTIONS} $*"
        ;;
    fractor)
        FRACTOR_DRY_RUN=""
        [ ${DRY_RUN} -eq 1 ] && FRACTOR_DRY_RUN="--dry-run"

        runComposerInstallInWorkingDir "Build/fractor"

        runPhpShellCommand \
            "fractor-${SUFFIX}" \
            ".Build/fractor/bin/fractor process --config=Build/fractor/fractor.php --ansi ${FRACTOR_DRY_RUN} ${EXTRA_TEST_OPTIONS} $*"
        ;;
    *)
        loadHelp >&2
        exit 1
        ;;
esac

SUITE_EXIT_CODE=$?
exit ${SUITE_EXIT_CODE}
