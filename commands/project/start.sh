## Start already created project environment
#
# Command
#    project:start
#    start

function project:start() {
    _checkProject
    if [ "${SYNC_MODE}" = "docker-sync" ]; then
        _logYellow "Skipping docker-sync because Mutagen config exists"
        _startDockerSync
    fi

    _logYellow "Starting docker containers"
    docker-compose start

    if [ "${SYNC_MODE}" = "mutagen" ]; then
        _startOrResumeMutagen
    fi
}

function start() {
    project:start
}

