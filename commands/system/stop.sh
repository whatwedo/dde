## Start already created system dde environment

function system:stop() {
    cd ${ROOT_DIR}
    docker-compose stop
}

