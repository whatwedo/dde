## Update dde system

function system:update() {
    _logRed "Removing dde (system)"
    system:destroy

    _logYellow "Updating dde repository"
    cd ${ROOT_DIR}
    git pull

    _logYellow "Updating docker images"
    cd $(ROOT_DIR)
    docker-compose pul
    docker-compose build --pull

    _logYellow "Starting dde (system)"
    system:up

    _logGreen "Finished update successfully"
}
