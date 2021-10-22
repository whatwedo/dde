#!/usr/bin/env bash

set -eo pipefail

DC="${DC:-exec}"
SOURCE=${BASH_SOURCE}
ROOT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" &>/dev/null && pwd)
CERT_DIR=${ROOT_DIR}/data/reverseproxy/etc/nginx/certs
HELP_DIR=${ROOT_DIR}/help
HELPER_DIR=${ROOT_DIR}/helper
NETWORK_NAME=dde
DOCKER_BUILDKIT=1
DDE_UID=$(id -u)
DDE_GID=$(id -g)
export DDE_UID
export DDE_GID

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


## Parse the actual arguments
_parse_args "${@}"

_checkCommand "${@}"

# This idea is heavily inspired by: https://github.com/adriancooney/Taskfile
"${@:-help}"
