## show the dde system env

function system:env() {
    _logGreen 'dde.sh system environment'
    echo OSTYPE=${OSTYPE}
    echo SOURCE=${SOURCE}
    echo ROOT_DIR=${ROOT_DIR}
    echo CERT_DIR=${CERT_DIR}
    echo HELPER_DIR=${HELPER_DIR}
    echo NETWORK_NAME=${NETWORK_NAME}
    echo DOCKER_BUILDKIT=${DOCKER_BUILDKIT}
    echo DDE_UID=${DDE_UID}
    echo DDE_GID=${DDE_GID}
    echo DDE_SH=${DDE_SH}

    if [ -f ${ROOT_DIR}/dde.local.sh ]; then
        _logYellow "include: ${ROOT_DIR}/dde.local.sh"
    fi
}
