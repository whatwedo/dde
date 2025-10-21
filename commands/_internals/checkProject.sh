
function _checkProject() {
    if [ "${ROOT_DIR}" == "$(pwd)" ]; then
        _logRed "dde root is not a valid project directory"
        exit 1
    fi

    if [ -f docker-compose.yml ]; then
        COMPOSE_FILE='docker-compose.yml'
    elif [ -f docker-compose.yaml ]; then
        COMPOSE_FILE='docker-compose.yaml'
    elif [ -f compose.yml ]; then
        COMPOSE_FILE='compose.yml'
    elif [ -f compose.yaml ]; then
        COMPOSE_FILE='compose.yaml'
    else
        _logRed "no composer file found"
        exit 1
    fi

    if [ -z "$(${DOCKER_BIN} network ls --filter=name=${NETWORK_NAME} -q)" ]; then
        _logRed "dde network not created. Please run dde system:up"
        exit 1
    fi

    mkdir -p .dde
    cp -R ${ROOT_DIR}/helper/configure-image.sh .dde/configure-image.sh
}
