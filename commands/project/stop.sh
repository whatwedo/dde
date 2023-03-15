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

    if [ "${SYNC_MODE}" = "docker-sync" ]; then
        _stopDockerSync
    fi
}

function stop() {
    project:stop
}

