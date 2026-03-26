#!/usr/bin/env bash

#
# Package-local TYPO3 extension test runner for feed_display.
#

set -eu -o pipefail

COMPOSER_FILES_STASHED=0

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

    if [ "${COMPOSER_FILES_STASHED:-0}" -eq 1 ]; then
        restoreComposerFiles || true
    fi

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
    if ${CONTAINER_BIN} network create "${NETWORK}" >/dev/null 2>&1; then
        return 0
    fi

    echo "Could not create container network ${NETWORK}" >&2
    echo "Trying stale container/network cleanup once ..." >&2
    cleanContainers

    if ${CONTAINER_BIN} network create "${NETWORK}" >/dev/null 2>&1; then
        echo "Container network ${NETWORK} created after cleanup retry." >&2
        return 0
    fi

    echo "Could not create container network ${NETWORK}" >&2
    echo "Container backend: ${CONTAINER_BIN}" >&2
    return 1
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

cleanComposer() {
    rm -rf .Build composer.lock
}

stashComposerFiles() {
    cp composer.json composer.json.orig
}

restoreComposerFiles() {
    if [ -f composer.json.orig ]; then
        mv composer.json.orig composer.json
    fi
}

loadHelp() {
    cat <<EOF
Package-local TYPO3 extension test runner for feed_display.

Usage: Build/Scripts/runTests.sh [options] [-- extra-args]

Options:
    -s <composer|composerInstall|composerValidate|lintPhp|lintJson|lintYaml|lintTypoScript|lintXliff|phpstanGenerateBaseline|coverageUnit|coverageFunctional|phpmd|unitRandom|cleanBuild|cleanCache|cleanTests|cleanContainers|lintServicesYaml|cgl|phpstan|functional|fractor|rector|unit|clean>
        Specifies which suite or command to run

    -t <12|13>
        TYPO3 major to use for composerInstall
            - 12: use TYPO3 12.4
            - 13: use TYPO3 13.4 (default)

    -p <8.1|8.2|8.3|8.4|8.5>
        PHP minor version to use
            - 8.1
            - 8.2 (default)
            - 8.3
            - 8.4
            - 8.5

    -d <sqlite|mariadb|mysql|postgres>
        Only with -s functional
        Database engine for functional tests
            - sqlite (default)
            - mariadb
            - mysql
            - postgres

    -a <mysqli|pdo_mysql>
        Only with -s functional and -d mariadb|mysql

    -i <version>
        Optional database version for mariadb/mysql/postgres

    -b <host|docker|podman>
        Execution backend

    -e "<tool options>"
        Pass-through options for phpunit/phpstan/php-cs-fixer/playwright

    -n
        Only with -s rector|fractor
        Activate dry-run mode

    -u <base-url>
        Only with -s playwright|e2e|accessibility
        Base URL for Playwright browser tests

    -x
        Enable xdebug

    -y <port>
        Xdebug port, default 9003

    -h
        Show this help

Examples:
    Build/Scripts/runTests.sh -s composerInstall
    Build/Scripts/runTests.sh -s composerValidate
    Build/Scripts/runTests.sh -s lintPhp
    Build/Scripts/runTests.sh -s cgl
    Build/Scripts/runTests.sh -s phpstan
    Build/Scripts/runTests.sh -s phpstanGenerateBaseline
    Build/Scripts/runTests.sh -s unit
    Build/Scripts/runTests.sh -s unitRandom
    Build/Scripts/runTests.sh -s functional
    Build/Scripts/runTests.sh -s lintJson
    Build/Scripts/runTests.sh -s lintYaml
    Build/Scripts/runTests.sh -s lintTypoScript
    Build/Scripts/runTests.sh -s lintXliff
    Build/Scripts/runTests.sh -s coverageUnit
    Build/Scripts/runTests.sh -s coverageFunctional
    Build/Scripts/runTests.sh -s phpmd
    Build/Scripts/runTests.sh -s lintServicesYaml
    Build/Scripts/runTests.sh -s fractor -n
    Build/Scripts/runTests.sh -s rector -n
    Build/Scripts/runTests.sh -s functional -d postgres
    Build/Scripts/runTests.sh -s cleanContainers
EOF
}

runPhpCommand() {
    local CONTAINER_NAME=${1}
    shift
    local COMMAND=("$@")
    if [ "${CONTAINER_BIN}" = "host" ]; then
        "${COMMAND[@]}"
        return
    fi
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name "${CONTAINER_NAME}" ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${EXTRA_ENV_PARAMS:-} ${IMAGE_PHP} "${COMMAND[@]}"
}

runPhpShellCommand() {
    local CONTAINER_NAME=${1}
    local COMMAND=${2}
    if [ "${CONTAINER_BIN}" = "host" ]; then
        local ENV_EXPORTS=""
        local HOST_COMMAND=${COMMAND}
        if [ ${PHP_XDEBUG_ON} -eq 0 ]; then
            ENV_EXPORTS="export XDEBUG_MODE=off;"
        else
            ENV_EXPORTS="export XDEBUG_MODE=debug XDEBUG_TRIGGER=feed_display XDEBUG_CONFIG=client_port=${PHP_XDEBUG_PORT};"
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
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name "${CONTAINER_NAME}" ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${EXTRA_ENV_PARAMS:-} ${IMAGE_PHP} /bin/sh -lc "${COMMAND}"
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

detectPlaywrightCommand() {
    if [ -n "${PLAYWRIGHT_COMMAND:-}" ]; then
        printf '%s' "${PLAYWRIGHT_COMMAND}"
        return
    fi
    if [ -f pnpm-lock.yaml ] && command -v pnpm >/dev/null 2>&1; then
        printf '%s' "pnpm exec playwright"
        return
    fi
    if [ -f yarn.lock ] && command -v yarn >/dev/null 2>&1; then
        printf '%s' "yarn playwright"
        return
    fi
    if command -v npx >/dev/null 2>&1; then
        printf '%s' "npx playwright"
        return
    fi
    echo "Could not determine a Playwright command. Install Node.js tooling or set PLAYWRIGHT_COMMAND." >&2
    exit 1
}

runHostShellCommand() {
    local COMMAND=${1}
    bash -lc "${COMMAND}"
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

ensureSuiteEnabled() {
    local ENABLED=${1}
    local SUITE_NAME=${2}
    if [ "${ENABLED}" != "1" ]; then
        echo "Suite not available in this scaffold: ${SUITE_NAME}" >&2
        exit 1
    fi
}

detectNodePackageManager() {
    if [ -f pnpm-lock.yaml ] && command -v pnpm >/dev/null 2>&1; then
        printf '%s' "pnpm"
        return
    fi
    if [ -f yarn.lock ] && command -v yarn >/dev/null 2>&1; then
        printf '%s' "yarn"
        return
    fi
    if command -v npm >/dev/null 2>&1; then
        printf '%s' "npm"
        return
    fi
    echo "Could not determine a Node.js package manager. Install npm, pnpm, or yarn." >&2
    exit 1
}

runPackageJsonScript() {
    local SCRIPT_NAME=${1}
    shift
    local NODE_PACKAGE_MANAGER
    NODE_PACKAGE_MANAGER=$(detectNodePackageManager)
    runHostShellCommand "${NODE_PACKAGE_MANAGER} run ${SCRIPT_NAME} -- $*"
}

ensurePlaywrightBaseUrl() {
    if [ -n "${PLAYWRIGHT_BASE_URL}" ]; then
        return
    fi
    echo "Playwright tests require -u <base-url> or PLAYWRIGHT_BASE_URL" >&2
    exit 1
}

runPlaywrightSuite() {
    ensurePlaywrightBaseUrl
    if [ ! -f "playwright.config.ts" ] && [ ! -f "playwright.config.js" ]; then
        echo "Playwright suite requested but no playwright.config.ts/js exists" >&2
        exit 1
    fi
    mkdir -p playwright/.auth
    PLAYWRIGHT_RUNNER=$(detectPlaywrightCommand)
    runHostShellCommand "PLAYWRIGHT_BASE_URL=${PLAYWRIGHT_BASE_URL} ${PLAYWRIGHT_RUNNER} test ${EXTRA_TEST_OPTIONS} $*"
}

THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
cd "${THIS_SCRIPT_DIR}/../../" || exit 1
ROOT_DIR="${PWD}"

TEST_SUITE=""
TYPO3_VERSION="13"
DBMS="sqlite"
DBMS_VERSION=""
DATABASE_DRIVER=""
PHP_VERSION="8.2"
PHP_XDEBUG_ON=0
PHP_XDEBUG_PORT=9003
EXTRA_TEST_OPTIONS=""
DRY_RUN=0
CONTAINER_BIN=""
CONTAINER_INTERACTIVE="--init"
HOST_UID=$(id -u)
HOST_GID=$(id -g)
SUFFIX="${RANDOM}-$$"
NETWORK="feed-display-${SUFFIX}"
CONTAINER_HOST="host.docker.internal"
PLAYWRIGHT_BASE_URL="${PLAYWRIGHT_BASE_URL:-${ACCEPTANCE_BASE_URL:-}}"
USERSET=""

while getopts "a:b:s:d:i:p:e:t:u:nxy:h" OPT; do
    case ${OPT} in
        s) TEST_SUITE=${OPTARG} ;;
        a) DATABASE_DRIVER=${OPTARG} ;;
        b) CONTAINER_BIN=${OPTARG} ;;
        d) DBMS=${OPTARG} ;;
        i) DBMS_VERSION=${OPTARG} ;;
        p) PHP_VERSION=${OPTARG} ;;
        e) EXTRA_TEST_OPTIONS=${OPTARG} ;;
        t) TYPO3_VERSION=${OPTARG} ;;
        u) PLAYWRIGHT_BASE_URL=${OPTARG} ;;
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

if ! [[ ${TYPO3_VERSION} =~ ^(12|13)$ ]]; then
    echo "Unsupported TYPO3 major: ${TYPO3_VERSION}" >&2
    exit 1
fi

if [ -n "${CONTAINER_BIN}" ] && ! [[ ${CONTAINER_BIN} =~ ^(host|docker|podman)$ ]]; then
    echo "Unsupported container backend: ${CONTAINER_BIN}" >&2
    exit 1
fi

if ! [[ ${PHP_VERSION} =~ ^(8.1|8.2|8.3|8.4|8.5)$ ]]; then
    echo "Unsupported PHP version: ${PHP_VERSION}" >&2
    exit 1
fi

handleDbmsOptions

case ${TEST_SUITE} in
    cleanBuild)
        rm -rf .Build composer.lock composer.json.orig composer.json.testing
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
        rm -rf .Build .cache .Build/coverage composer.lock composer.json.orig composer.json.testing .phpunit.cache playwright-report test-results playwright/.auth
        exit 0
        ;;
    playwright)
        runPlaywrightSuite "$@"
        exit $?
        ;;
esac

if [ -z "${CONTAINER_BIN}" ]; then
    if command -v podman >/dev/null 2>&1; then
        CONTAINER_BIN="podman"
    elif command -v docker >/dev/null 2>&1; then
        CONTAINER_BIN="docker"
    else
        echo "This script relies on docker or podman unless -b host is used explicitly" >&2
        exit 1
    fi
elif [ "${CONTAINER_BIN}" != "host" ] && ! command -v "${CONTAINER_BIN}" >/dev/null 2>&1; then
    echo "Requested container backend not found: ${CONTAINER_BIN}" >&2
    exit 1
fi

if [ "$(uname)" != "Darwin" ] && [ "${CONTAINER_BIN}" = "docker" ]; then
    USERSET="--user ${HOST_UID}:${HOST_GID}"
fi

if [ -t 0 ] && [ -t 1 ]; then
    CONTAINER_INTERACTIVE="-it --init"
fi

mkdir -p .cache .Build/Web/typo3temp/var/tests .Build/coverage playwright/.auth

IMAGE_PHP="ghcr.io/typo3/core-testing-php${PHP_VERSION//./}:latest"
IMAGE_MARIADB="docker.io/mariadb:${DBMS_VERSION}"
IMAGE_MYSQL="docker.io/mysql:${DBMS_VERSION}"
IMAGE_POSTGRES="docker.io/postgres:${DBMS_VERSION}-alpine"
COMPOSER_ROOT_VERSION="${TYPO3_VERSION}.0.0-dev"

if [ "${CONTAINER_BIN}" != "host" ]; then
    if ! createContainerNetwork; then
        exit 1
    fi

    if [ "${CONTAINER_BIN}" = "docker" ]; then
        CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm --network ${NETWORK} --add-host ${CONTAINER_HOST}:host-gateway ${USERSET} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
    else
        CONTAINER_HOST="host.containers.internal"
        CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm --network ${NETWORK} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
    fi
fi

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
        cleanComposer
        stashComposerFiles
        COMPOSER_FILES_STASHED=1
        case ${TYPO3_VERSION} in
            12)
                TYPO3_REQUIREMENTS=(
                    typo3/cms-backend:^12.4
                    typo3/cms-core:^12.4
                    typo3/cms-extbase:^12.4
                    typo3/cms-fluid:^12.4
                    typo3/cms-frontend:^12.4
                )
                TYPO3_DEV_REQUIREMENTS=(
                    typo3/cms-fluid-styled-content:^12.4
                    typo3/cms-install:^12.4
                    typo3/testing-framework:^7.0
                    phpunit/phpunit:^10.5
                )
                ;;
            13)
                TYPO3_REQUIREMENTS=(
                    typo3/cms-backend:^13.4
                    typo3/cms-core:^13.4
                    typo3/cms-extbase:^13.4
                    typo3/cms-fluid:^13.4
                    typo3/cms-frontend:^13.4
                )
                TYPO3_DEV_REQUIREMENTS=(
                    typo3/cms-fluid-styled-content:^13.4
                    typo3/cms-install:^13.4
                    typo3/testing-framework:^8.0
                    phpunit/phpunit:^10.5
                )
                ;;
            *)
                echo "Unsupported TYPO3 major for composerInstall: ${TYPO3_VERSION}" >&2
                exit 1
                ;;
        esac
        runPhpCommand "composer-require-${SUFFIX}" composer require --no-ansi --no-interaction --no-progress --no-update "${TYPO3_REQUIREMENTS[@]}"
        runPhpCommand "composer-require-dev-${SUFFIX}" composer require --dev --no-ansi --no-interaction --no-progress --no-update "${TYPO3_DEV_REQUIREMENTS[@]}"
        runPhpCommand "composer-install-${SUFFIX}" composer install --no-ansi --no-interaction --no-progress "$@"
        cp composer.json composer.json.testing
        restoreComposerFiles
        COMPOSER_FILES_STASHED=0
        ;;
    composerValidate)
        runPhpCommand "composer-validate-${SUFFIX}" composer validate --no-check-lock "$@"
        ;;
    lintPhp)
        runPhpShellCommand "lint-php-${SUFFIX}" ".Build/bin/parallel-lint --exclude .Build --exclude .cache . Build Classes Configuration Tests"
        ;;
    lintJson)
        runPhpShellCommand "lint-json-${SUFFIX}" "find . ! -path '*/.Build/*' ! -path '*/node_modules/*' -name '*.json' -print0 | xargs -0 -r .Build/bin/jsonlint -q"
        ;;
    lintYaml)
        runPhpShellCommand "lint-yaml-${SUFFIX}" "find . ! -path '*/.Build/*' ! -path '*/node_modules/*' \\( -name '*.yaml' -o -name '*.yml' \\) -print0 | xargs -0 -r .Build/bin/yaml-lint"
        ;;
    lintServicesYaml)
        ensureSuiteEnabled "1" "lintServicesYaml"
        runPhpShellCommand "lint-services-yaml-${SUFFIX}" "find . ! -path '*/.Build/*' ! -path '*/node_modules/*' \\( -name 'Services.yaml' -o -name 'Services.yml' \\) -print0 | xargs -0 -r .Build/bin/yaml-lint"
        ;;
    lintTypoScript)
        runPhpShellCommand "lint-typoscript-${SUFFIX}" "find Configuration Tests -type f \\( -name '*.typoscript' -o -name '*.tsconfig' \\) ! -path '*/.Build/*' ! -path '*/node_modules/*' -print0 2>/dev/null | xargs -0 -r .Build/bin/typoscript-lint -c Build/typoscript-lint/config.yml --ansi -n --fail-on-warnings -vvv"
        ;;
    lintXliff)
        runPhpShellCommand "lint-xliff-${SUFFIX}" "find Configuration ContentBlocks Resources Tests -type f \\( -name '*.xlf' -o -name '*.xliff' \\) ! -path '*/.Build/*' ! -path '*/node_modules/*' -print0 2>/dev/null | xargs -0 -r php Build/Scripts/xliffLint.sh lint:xliff"
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

        if [ "${PHP_VERSION}" = "8.1" ]; then
            PHP_VERSION="8.2"
        fi

        runComposerInstallInWorkingDir "Build/fractor"

        runPhpShellCommand \
            "fractor-${SUFFIX}" \
            ".Build/fractor/bin/fractor process --config=Build/fractor/fractor.php --ansi ${FRACTOR_DRY_RUN} ${EXTRA_TEST_OPTIONS} $*"
        ;;
    npm)
        ensureSuiteEnabled "0" "npm"
        NODE_PACKAGE_MANAGER=$(detectNodePackageManager)
        if [ $# -gt 0 ]; then
            runHostShellCommand "${NODE_PACKAGE_MANAGER} $*"
        elif [ "${NODE_PACKAGE_MANAGER}" = "pnpm" ]; then
            runHostShellCommand "pnpm install --frozen-lockfile"
        elif [ "${NODE_PACKAGE_MANAGER}" = "yarn" ]; then
            runHostShellCommand "yarn install --frozen-lockfile"
        elif [ -f package-lock.json ]; then
            runHostShellCommand "npm ci"
        else
            runHostShellCommand "npm install"
        fi
        ;;
    build)
        ensureSuiteEnabled "0" "build"
        runPackageJsonScript "build" "$@"
        ;;
    lintScss)
        ensureSuiteEnabled "0" "lintScss"
        runPackageJsonScript "check:scss:lint" "$@"
        ;;
    lintTypescript)
        ensureSuiteEnabled "0" "lintTypescript"
        runPackageJsonScript "check:typescript:lint" "$@"
        ;;
    unitJavascript)
        ensureSuiteEnabled "0" "unitJavascript"
        runPackageJsonScript "check:tests:unit:javascript" "$@"
        ;;
    e2e)
        ensureSuiteEnabled "0" "e2e"
        ensurePlaywrightBaseUrl
        runHostShellCommand "PLAYWRIGHT_BASE_URL=${PLAYWRIGHT_BASE_URL} $(detectNodePackageManager) run check:tests:e2e -- $*"
        ;;
    e2e-prepare)
        ensureSuiteEnabled "0" "e2e-prepare"
        runPackageJsonScript "check:tests:e2e:prepare" "$@"
        ;;
    accessibility)
        ensureSuiteEnabled "0" "accessibility"
        ensurePlaywrightBaseUrl
        runHostShellCommand "PLAYWRIGHT_BASE_URL=${PLAYWRIGHT_BASE_URL} $(detectNodePackageManager) run check:tests:accessibility -- $*"
        ;;
    accessibility-prepare)
        ensureSuiteEnabled "0" "accessibility-prepare"
        runPackageJsonScript "check:tests:accessibility:prepare" "$@"
        ;;
    *)
        loadHelp >&2
        exit 1
        ;;
esac

SUITE_EXIT_CODE=$?
exit ${SUITE_EXIT_CODE}
