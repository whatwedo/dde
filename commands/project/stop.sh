## Stop project environment
#
# Command
#    project:stop

function project:stop() {
    _checkProject

    _logYellow "Stopping docker containers"
    docker-compose stop

    if [ "${SYNC_MODE}" = "mutagen" ]; then
        _pauseMutagen
    fi

    if [ "${SYNC_MODE}" = "docker-sync" ]; then
        _stopDockerSyn
    fi
}

