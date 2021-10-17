## Creates and starts project containers
#
# Command
#    project:up
#
# Creates and starts project containers
#
# must be started in the project directory, where the docker-composer.yml is located

function project:up() {
    _checkProject

    _logYellow "Generating SSL cert"

    for vhost in $(grep VIRTUAL_HOST= docker-compose.yml | cut -d'=' -f2); do
        ${HELPER_DIR}/generate-vhost-cert.sh ${CERT_DIR} ${vhost}
    done

    if [ "${SYNC_MODE}" = "docker-sync" ]; then
        _logYellow "Skipping docker-sync because Mutagen config exists"
        _startDockerSync
    fi

    _logYellow "Starting containers"
    docker-compose up -d

    if [ "${SYNC_MODE}" = "mutagen" ]; then
        _startOrResumeMutagen
    fi
    _logGreen "Finished startup successfully"

    for vhost in $(grep VIRTUAL_HOST= docker-compose.yml | cut -d'=' -f2); do
        _logYellow "visit: https://${vhost}"
    done

}
