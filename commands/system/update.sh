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
    docker-compose pull
    docker pull mikefarah/yq
    docker-compose build --pull

    _logYellow "Starting dde (system)"
    system:up

    _logGreen "Finished update successfully"
}

function system-update() {
    system:update
}
