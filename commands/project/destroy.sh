## Remove central project infrastructure
#
# Command
#    project:destroy
#    destroy

function project:destroy() {
    _checkProject

    _logYellow "Removing containers"
    ${DOCKER_COMPOSE} down --remove-orphans

    _logYellow "Deleting SSL certs"

    for vhost in $(${DOCKER_COMPOSE} config | _yq_stdin e '.services.*.environment.VIRTUAL_HOST' | grep -v null); do
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

