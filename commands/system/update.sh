## Update dde system
#
# Command
#    system:update
#    system-update

function system:update() {
    _logRed "Removing dde (system)"
    system:destroy

    _logYellow "Updating dde repository"
    cd ${ROOT_DIR}
    git pull

    _logYellow "Updating docker images"
    cd ${ROOT_DIR}
    ${DOCKER_COMPOSE} pull
    docker pull mikefarah/yq
    ${DOCKER_COMPOSE} build --pull

    _logYellow "Starting dde (system)"
    system:up

    _logGreen "Finished update successfully"
}

function system-update() {
    system:update
}
