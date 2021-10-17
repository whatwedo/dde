function _startOrResumeMutagen() {
    if [ "${SYNC_MODE}" != "mutagen" ]; then
        return
    fi

    if [ $(which mutagen) ]; then
        _logYellow "Mutagen is installed"
    else
        _logRed "Mutagen is not installed, see: https://mutagen.io"
        exit 1
    fi
    _logYellow "Starting Mutagen. This can take several minutes depending on your project size"
    mutagen project resume 2>/dev/null || mutagen project start
    mutagen project flush
}

function _pauseMutagen() {
    if [ "${SYNC_MODE}" != "mutagen" ]; then
        return
    fi

    _logYellow "Stopping Mutagen"
    mutagen project pause
}

function _terminateMutagen() {
    if [ "${SYNC_MODE}" != "mutagen" ]; then
        return
    fi
    _logYellow "Terminating Mutagen"
    mutagen project terminate
}

