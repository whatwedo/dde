## Stop project environment
#
# Command
#    project:stop
#    stop

function project:stop() {
    _checkProject

    _logYellow "Stopping docker containers"
    ${DOCKER_COMPOSE} stop

    if [ "${SYNC_MODE}" = "mutagen" ]; then
        _pauseMutagen
    fi
}

function stop() {
    project:stop
}

