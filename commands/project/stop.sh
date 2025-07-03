## Stop project environment
#
# Command
#    project:stop
#    stop

function project:stop() {
    _checkProject

    _logYellow "Stopping docker containers"
    ${DOCKER_COMPOSE} stop
}

function stop() {
    project:stop
}

