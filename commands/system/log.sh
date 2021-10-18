## Show log output of system services

function system:log() {
    cd ${ROOT_DIR}
    docker-compose logs -f --tail=100
}
