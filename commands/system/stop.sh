## Start already created system dde environment
#
# Command
#    system:status

function system:stop() {
    cd ${ROOT_DIR}
    docker-compose stop
}

