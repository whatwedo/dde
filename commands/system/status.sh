## Print dde system status

function system:status() {
    cd ${ROOT_DIR}
    docker-compose ps
}

