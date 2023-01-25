function _ddeCheckNetwork() {
    if [ "$(docker network ls --filter=name=${NETWORK_NAME} -q)" == "" ]; then
        _logYellow "Create network ${NETWORK_NAME}"
        docker network create ${NETWORK_NAME}
    else
        _logGreen "Network ${NETWORK_NAME} exists"
    fi
}
