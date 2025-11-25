function _ensureNetwork() {
    _logYellow "Creating network if required"
    if [ "$(${DOCKER_BIN} network ls --filter=name=^${NETWORK_NAME}$ -q)" == "" ]; then
        _logGreen "Create network ${NETWORK_NAME}"
        ${DOCKER_BIN} network create ${NETWORK_NAME}
    fi
}
