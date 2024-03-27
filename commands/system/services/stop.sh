## Stop dde system service
#
# Command
#    system:services:stop <service>

function system:services:stop() {
    cd ${ROOT_DIR}

    if [ -d "services/${1}" ]; then
        _logGreen "System service: ${1} found"
    else
        _logRed "System service: :${1}: not found"
        return
    fi

    _logYellow "Enable System services: ${1}"
    cd services

    for f in *; do
    if [ -d "$f" ]; then
        # Will not run if no directories are available
        if [ -f "${f}/docker-compose.yml" ]; then
            if [ "$f" == "${1}" ]; then
                cd ${f}
                ${DOCKER_COMPOSE} stop
            fi
        fi
    fi
done
}
