
function _startDockerSync() {
    if [ "${SYNC_MODE}" != "docker-sync" ]; then
        return
    fi

    if [ $(which docker-sync) ]; then
        _logYellow "docker-sync is installed"
    else
        _logRed "docker-sync is not installed, see: https://docker-sync.io"
        exit 1
    fi
    _logYellow "Starting docker-sync. This can take several minutes depending on your project size"
    docker-sync stop
    docker-sync start
}

function _stopDockerSync() {
    if [ "${SYNC_MODE}" != "docker-sync" ]; then
        return
    fi

    _logYellow "Stopping docker-sync"
    docker-sync stop
}

function _cleanDockerSync() {
    if [ "${SYNC_MODE}" != "docker-sync" ]; then
        return
    fi

    _logYellow "Cleaning up docker-sync"
    _stopDockerSync
    docker-sync clean
}

