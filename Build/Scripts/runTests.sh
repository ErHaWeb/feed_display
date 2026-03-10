#!/usr/bin/env bash

#
# Local feed_display test runner based on docker.
#

trap 'cleanUp;exit 2' SIGINT

waitFor() {
    local HOST=${1}
    local PORT=${2}
    local TESTCOMMAND="
        COUNT=0;
        while ! nc -z ${HOST} ${PORT}; do
            if [ \"\${COUNT}\" -gt 20 ]; then
                echo \"Can not connect to ${HOST} port ${PORT}. Aborting.\";
                exit 1;
            fi;
            sleep 1;
            COUNT=\$((COUNT + 1));
        done;
    "
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name wait-for-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} /bin/sh -c "${TESTCOMMAND}"
    if [[ $? -gt 0 ]]; then
        kill -SIGINT -$$
    fi
}

cleanUp() {
    if [ -z "${CONTAINER_BIN}" ] || [ -z "${NETWORK}" ]; then
        return
    fi

    # Local reruns often happen after aborted sessions. Silence missing container/network
    # cleanup noise so the final suite result stays readable.
    ATTACHED_CONTAINERS=$(${CONTAINER_BIN} ps --filter network=${NETWORK} --format='{{.Names}}' 2>/dev/null)
    for ATTACHED_CONTAINER in ${ATTACHED_CONTAINERS}; do
        ${CONTAINER_BIN} kill ${ATTACHED_CONTAINER} >/dev/null 2>&1
    done

    ${CONTAINER_BIN} network rm ${NETWORK} >/dev/null 2>&1
}

handleDbmsOptions() {
    # -a, -d, -i depend on each other. Validate input combinations and set defaults.
    case ${DBMS} in
        mariadb)
            [ -z "${DATABASE_DRIVER}" ] && DATABASE_DRIVER="mysqli"
            if [ "${DATABASE_DRIVER}" != "mysqli" ] && [ "${DATABASE_DRIVER}" != "pdo_mysql" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                exit 1
            fi
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="10.11"
            ;;
        mysql)
            [ -z "${DATABASE_DRIVER}" ] && DATABASE_DRIVER="mysqli"
            if [ "${DATABASE_DRIVER}" != "mysqli" ] && [ "${DATABASE_DRIVER}" != "pdo_mysql" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                exit 1
            fi
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="8.0"
            ;;
        postgres)
            if [ -n "${DATABASE_DRIVER}" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                exit 1
            fi
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="16"
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
}

cleanComposer() {
    rm -rf \
        .Build \
        composer.lock
}

stashComposerFiles() {
    cp composer.json composer.json.orig
}

restoreComposerFiles() {
    cp composer.json composer.json.testing
    mv composer.json.orig composer.json
}

loadHelp() {
    # Load help text into $HELP
    read -r -d '' HELP <<EOF
Local test runner for the feed_display extension.

Usage: $0 [options] [file]

Options:
    -s <composer|composerInstall|functional|rector|unit|clean>
        Specifies which suite or command to run

    -t <12|13>
        TYPO3 major to install for composerInstall
            - 12: use TYPO3 12.4
            - 13: use TYPO3 13.4 (default)

    -p <8.1|8.2|8.3>
        PHP minor version to use
            - 8.1
            - 8.2 (default)
            - 8.3

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

    -b <docker|podman>
        Container environment

    -e "<phpunit options>"
        Deprecated pass-through for phpunit options

    -x
        Enable xdebug for unit or functional tests

    -y <port>
        Xdebug port, default 9003

    -n
        Only with -s rector
        Activate dry-run so rector reports required changes without modifying files

    -h
        Show this help

Examples:
    Build/Scripts/runTests.sh -s composerInstall
    Build/Scripts/runTests.sh -s unit
    Build/Scripts/runTests.sh -s functional
    Build/Scripts/runTests.sh -s rector -n
    Build/Scripts/runTests.sh -s functional -d postgres
    Build/Scripts/runTests.sh -s composer -- show typo3/cms-core
EOF
}

if ! type "docker" >/dev/null 2>&1 && ! type "podman" >/dev/null 2>&1; then
    echo "This script relies on docker or podman. Please install at least one of them" >&2
    exit 1
fi

THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
cd "$THIS_SCRIPT_DIR" || exit 1
cd ../../ || exit 1
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
# Add the shell PID to the suffix so repeated local runs do not collide on Docker network names.
SUFFIX="${RANDOM}-$$"
NETWORK="feed-display-${SUFFIX}"
CONTAINER_HOST="host.docker.internal"
USERSET=""

OPTIND=1
INVALID_OPTIONS=()
while getopts "a:b:s:d:i:p:e:t:xy:nh" OPT; do
    case ${OPT} in
        s)
            TEST_SUITE=${OPTARG}
            ;;
        a)
            DATABASE_DRIVER=${OPTARG}
            ;;
        b)
            if ! [[ ${OPTARG} =~ ^(docker|podman)$ ]]; then
                INVALID_OPTIONS+=("-b ${OPTARG}")
            fi
            CONTAINER_BIN=${OPTARG}
            ;;
        d)
            DBMS=${OPTARG}
            ;;
        i)
            DBMS_VERSION=${OPTARG}
            ;;
        p)
            PHP_VERSION=${OPTARG}
            if ! [[ ${PHP_VERSION} =~ ^(8.1|8.2|8.3)$ ]]; then
                INVALID_OPTIONS+=("-p ${OPTARG}")
            fi
            ;;
        e)
            EXTRA_TEST_OPTIONS=${OPTARG}
            ;;
        t)
            TYPO3_VERSION=${OPTARG}
            if ! [[ ${TYPO3_VERSION} =~ ^(12|13)$ ]]; then
                INVALID_OPTIONS+=("-t ${OPTARG}")
            fi
            ;;
        x)
            PHP_XDEBUG_ON=1
            ;;
        y)
            PHP_XDEBUG_PORT=${OPTARG}
            ;;
        n)
            DRY_RUN=1
            ;;
        h)
            loadHelp
            echo "${HELP}"
            exit 0
            ;;
        \?)
            INVALID_OPTIONS+=("-${OPTARG}")
            ;;
        :)
            INVALID_OPTIONS+=("-${OPTARG}")
            ;;
    esac
done

if [ ${#INVALID_OPTIONS[@]} -ne 0 ]; then
    echo "Invalid option(s):" >&2
    for INVALID_OPTION in "${INVALID_OPTIONS[@]}"; do
        echo "${INVALID_OPTION}" >&2
    done
    echo >&2
    loadHelp
    echo "${HELP}" >&2
    exit 1
fi

handleDbmsOptions

if [[ -z "${CONTAINER_BIN}" ]]; then
    if type "podman" >/dev/null 2>&1; then
        CONTAINER_BIN="podman"
    else
        CONTAINER_BIN="docker"
    fi
fi

if [ $(uname) != "Darwin" ] && [ "${CONTAINER_BIN}" == "docker" ]; then
    USERSET="--user $HOST_UID:$HOST_GID"
fi

if [ -t 0 ] && [ -t 1 ]; then
    CONTAINER_INTERACTIVE="-it --init"
fi

mkdir -p .cache
mkdir -p .Build/Web/typo3temp/var/tests

IMAGE_PHP="ghcr.io/typo3/core-testing-php${PHP_VERSION//./}:latest"
IMAGE_MARIADB="docker.io/mariadb:${DBMS_VERSION}"
IMAGE_MYSQL="docker.io/mysql:${DBMS_VERSION}"
IMAGE_POSTGRES="docker.io/postgres:${DBMS_VERSION}-alpine"
COMPOSER_ROOT_VERSION="${TYPO3_VERSION}.0.0-dev"

shift $((OPTIND - 1))

if ! ${CONTAINER_BIN} network create ${NETWORK} >/dev/null 2>&1; then
    echo "Could not create container network ${NETWORK}" >&2
    exit 1
fi

if [ "${CONTAINER_BIN}" == "docker" ]; then
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm --network ${NETWORK} --add-host ${CONTAINER_HOST}:host-gateway ${USERSET} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
else
    CONTAINER_HOST="host.containers.internal"
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm --network ${NETWORK} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
fi

if [ ${PHP_XDEBUG_ON} -eq 0 ]; then
    XDEBUG_MODE="-e XDEBUG_MODE=off"
    XDEBUG_CONFIG=" "
else
    XDEBUG_MODE="-e XDEBUG_MODE=debug -e XDEBUG_TRIGGER=feed_display"
    XDEBUG_CONFIG="client_port=${PHP_XDEBUG_PORT} client_host=${CONTAINER_HOST}"
fi

case ${TEST_SUITE} in
    clean)
        rm -rf \
            .Build \
            .cache \
            composer.lock \
            composer.json.orig \
            composer.json.testing
        SUITE_EXIT_CODE=$?
        ;;
    composer)
        COMMAND=(composer "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-command-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    composerInstall)
        cleanComposer
        stashComposerFiles
        if [ "${TYPO3_VERSION}" = "12" ]; then
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
        else
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
        fi
        COMMAND=(composer require --no-ansi --no-interaction --no-progress --no-update "${TYPO3_REQUIREMENTS[@]}")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-require-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        if [ ${SUITE_EXIT_CODE} -eq 0 ]; then
            COMMAND=(composer require --dev --no-ansi --no-interaction --no-progress --no-update "${TYPO3_DEV_REQUIREMENTS[@]}")
            ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-require-dev-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} "${COMMAND[@]}"
            SUITE_EXIT_CODE=$?
        fi
        if [ ${SUITE_EXIT_CODE} -eq 0 ]; then
            COMMAND=(composer install --no-ansi --no-interaction --no-progress "$@")
            ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-install-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} "${COMMAND[@]}"
            SUITE_EXIT_CODE=$?
        fi
        restoreComposerFiles
        ;;
    functional)
        COMMAND=(.Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --exclude-group not-${DBMS} ${EXTRA_TEST_OPTIONS} "$@")
        case ${DBMS} in
            mariadb)
                ${CONTAINER_BIN} run --rm --name mariadb-func-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MARIADB} >/dev/null
                waitFor mariadb-func-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mariadb-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            mysql)
                ${CONTAINER_BIN} run --rm --name mysql-func-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MYSQL} >/dev/null
                waitFor mysql-func-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mysql-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            postgres)
                ${CONTAINER_BIN} run --rm --name postgres-func-${SUFFIX} --network ${NETWORK} -d -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=funcu --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid ${IMAGE_POSTGRES} >/dev/null
                waitFor postgres-func-${SUFFIX} 5432
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_pgsql -e typo3DatabaseName=bamboo -e typo3DatabaseUsername=funcu -e typo3DatabaseHost=postgres-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            sqlite)
                mkdir -p "${ROOT_DIR}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs/"
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_sqlite --tmpfs ${ROOT_DIR}/.Build/Web/typo3temp/var/tests/functional-sqlite-dbs/:rw,noexec,nosuid"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
        esac
        ;;
    rector)
        DRY_RUN_OPTIONS=''
        if [ "${DRY_RUN}" -eq 1 ]; then
            DRY_RUN_OPTIONS='--dry-run'
        fi
        COMMAND="php -dxdebug.mode=off .Build/bin/rector process ${DRY_RUN_OPTIONS} --config=Build/rector/rector.php --no-progress-bar --ansi"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name rector-${SUFFIX} -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    unit)
        COMMAND=(.Build/bin/phpunit -c Build/phpunit/UnitTests.xml --exclude-group not-${DBMS} ${EXTRA_TEST_OPTIONS} "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    *)
        loadHelp
        echo "${HELP}" >&2
        cleanUp
        exit 1
        ;;
esac

cleanUp

echo "" >&2
echo "###########################################################################" >&2
echo "Result of ${TEST_SUITE}" >&2
echo "PHP: ${PHP_VERSION}" >&2
echo "TYPO3: ${TYPO3_VERSION}" >&2
echo "CONTAINER_BIN: ${CONTAINER_BIN}" >&2
if [[ ${TEST_SUITE} =~ ^functional$ ]]; then
    case "${DBMS}" in
        mariadb|mysql)
            echo "DBMS: ${DBMS} version ${DBMS_VERSION} driver ${DATABASE_DRIVER}" >&2
            ;;
        postgres)
            echo "DBMS: ${DBMS} version ${DBMS_VERSION} driver pdo_pgsql" >&2
            ;;
        sqlite)
            echo "DBMS: ${DBMS} driver pdo_sqlite" >&2
            ;;
    esac
fi
if [[ -n ${EXTRA_TEST_OPTIONS} ]]; then
    echo "Note: Using -e is deprecated. Pass phpunit options after -- instead." >&2
fi
if [[ ${SUITE_EXIT_CODE} -eq 0 ]]; then
    echo "SUCCESS" >&2
else
    echo "FAILURE" >&2
fi
echo "###########################################################################" >&2
echo "" >&2

exit ${SUITE_EXIT_CODE}
