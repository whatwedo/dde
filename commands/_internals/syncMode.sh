

function _syncMode() {
    SYNC_MODE=volume
    if [ -f ./mutagen.yml ]; then
        SYNC_MODE=mutagen
    fi

    if [ -f ./docker-compose.override.yml ]; then
        if [ $(grep "^x-dde-sync:" docker-compose.override.yml | cut -d':' -f2) ]; then
            SYNC_MODE=$(grep "x-dde-sync:" docker-compose.override.yml | cut -d':' -f2 | xargs)
        fi
    fi

    if [[ "${SYNC_MODE}" != "volume" ]] && [[ "${SYNC_MODE}" != "mutagen" ]]; then
        _logRed "Unknown Sync mode ${SYNC_MODE}"
        exit 1
    fi
}
