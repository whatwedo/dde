## Print dde services
#
# Command
#    system:services

function system:services() {
    cd ${ROOT_DIR}
    docker-compose ps --services
}
