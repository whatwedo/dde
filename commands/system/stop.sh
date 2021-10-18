## Start already created system dde environment
#
# Command
#    system:stop
#    system-stop

function system:stop() {
    cd ${ROOT_DIR}
    docker-compose stop
}

function system-stop() {
    system:stop
}

