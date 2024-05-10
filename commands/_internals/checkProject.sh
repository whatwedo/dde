
function _checkProject() {
    if [ "${ROOT_DIR}" == "$(pwd)" ]; then
        _logRed "dde root is not a valid project directory"
        exit 1
    fi
    if [ ! -f docker-compose.yml ]; then
        _logRed "docker-compose.yml not found"
        exit 1
    fi
    if [ -z $(docker network ls --filter=name=${NETWORK_NAME} -q) ]; then
        _logRed "dde network not created. Please run dde system:up"
        exit 1
    fi

    mkdir -p .dde
    cp -R ${ROOT_DIR}/helper/configure-image.sh .dde/configure-image.sh
}
