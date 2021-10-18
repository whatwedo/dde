## Remove central project infrastructure
#
# Command
#    project:destroy
#    destroy

function project:destroy() {
    _checkProject

    _logYellow "Removing containers"
    docker-compose down -v --remove-orphans

    _logYellow "Deleting SSL certs"

    for vhost in $(grep VIRTUAL_HOST= docker-compose.yml | cut -d'=' -f2); do
        _logYellow "Delete certs for ${vhost}"
        rm -f ${CERT_DIR}/${vhost}.*
    done

    if [ "${SYNC_MODE}" = "mutagen" ]; then
        _terminateMutagen
    fi

    if [ "${SYNC_MODE}" = "docker-sync" ]; then
        _cleanDockerSync
    fi

    _logGreen "Finished destroying successfully"
}

function destroy() {
    project:destroy
}

