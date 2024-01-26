## show the dde system env
#
# Command
#    system:env
#    s:e

function system:env() {
    _logGreen 'dde.sh system environment'
    echo OSTYPE=${OSTYPE}
    echo SOURCE=${SOURCE}
    echo ROOT_DIR=${ROOT_DIR}
    _logYellow "DATA_DIR (Deprecated, use DDE_DATA_HOME) = ${DATA_DIR}"
    _logYellow "CERT_DIR (Deprecated, use DDE_CERT_PATH) = ${CERT_DIR}"
    echo DDE_DATA_HOME=${DDE_DATA_HOME}
    echo DDE_CERT_PATH=${DDE_CERT_PATH}
    echo HELPER_DIR=${HELPER_DIR}
    echo NETWORK_NAME=${NETWORK_NAME}
    echo DOCKER_BUILDKIT=${DOCKER_BUILDKIT}
    echo DDE_UID=${DDE_UID}
    echo DDE_GID=${DDE_GID}
    echo ""
    _logGreen "Available services"
    system:services
}

function s:e() {
    system:env
}
