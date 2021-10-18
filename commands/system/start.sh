## Start already created system dde environment

function system:start() {
    cd ${ROOT_DIR}
    docker-compose start
    _addSshKey
}

