#!/usr/bin/env bash

set -eo pipefail

DC="${DC:-exec}"
SOURCE=${BASH_SOURCE}
ROOT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" &>/dev/null && pwd)
CERT_DIR=${ROOT_DIR}/data/reverseproxy/etc/nginx/certs
DATA_DIR=${ROOT_DIR}/data
HELP_DIR=${ROOT_DIR}/help
HELPER_DIR=${ROOT_DIR}/helper
NETWORK_NAME=dde
DOCKER_BUILDKIT=1


DDE_DATA_HOME="$HOME/.dde"
DDE_CERT_PATH="$DDE_DATA_HOME/data/reverseproxy/etc/nginx/certs"
DDE_UID=$(id -u)
DDE_GID=$(id -g)
DDE_BROWSER=
DDE_CONTAINER_SHELL=sh
export DDE_UID
export DDE_GID
export DDE_CONTAINER_SHELL
export DDE_DATA_HOME

# If we're running in CI we need to disable TTY allocation for docker-compose
# commands that enable it by default, such as exec and run.
TTY=""
if [[ ! -t 1 ]]; then
    TTY="-T"
fi



for commandFile in $(find ${ROOT_DIR}/commands -type f -name "*.sh"); do
    if [[ ${commandFile} != "local.sh" ]]; then
        source ${commandFile}
    fi
done

_syncMode


if [[ -f  ${ROOT_DIR}/commands/local.sh ]]; then
    source ${ROOT_DIR}/commands/local.sh
fi

# Check if 'docker-compose' (the older version with a hyphen) is installed
if command -v docker-compose >/dev/null 2>&1; then
    # Set DOCKER_COMPOSE variable to 'docker-compose' if the older version is found
    DOCKER_COMPOSE='docker-compose'
# Check if 'docker compose' (the newer version without a hyphen) is installed
elif command -v docker >/dev/null 2>&1 && docker compose --version >/dev/null 2>&1; then
    # Set DOCKER_COMPOSE variable to 'docker compose' if the newer version is found
    DOCKER_COMPOSE='docker compose'
fi

## Parse the actual arguments
_parse_args "${@}"

_checkCommand "${@}"

# This idea is heavily inspired by: https://github.com/adriancooney/Taskfile
"${@:-help}"
