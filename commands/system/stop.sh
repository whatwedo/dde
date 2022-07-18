## Start already created system dde environment
#
# Command
#    system:stop
#    system-stop

function system:stop() {
    cd ${ROOT_DIR}
    ${DOCKER_COMPOSE} stop
}

function system-stop() {
    system:stop
}

