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
    echo DATA_DIR=${DATA_DIR}
    echo CERT_DIR=${CERT_DIR}
    echo HELPER_DIR=${HELPER_DIR}
    echo NETWORK_NAME=${NETWORK_NAME}
    echo DOCKER_BUILDKIT=${DOCKER_BUILDKIT}
    echo DDE_UID=${DDE_UID}
    echo DDE_GID=${DDE_GID}

    echo ""

    _logGreen "Docker Server";
    echo Docker Server Version $(${DOCKER_BIN} info --format '{{json .ServerVersion}}')

    echo ""

    system:services
}

function s:e() {
    system:env
}
