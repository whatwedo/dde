## Start already created system dde environment
#
# Command
#    system:stop
#    system-stop

function system:stop() {
    cd ${ROOT_DIR}

    cd services

    for f in *; do
    if [ -d "$f" ]; then
        # Will not run if no directories are available
        if [ -f "${f}/docker-compose.yml" ]; then
            cd ${ROOT_DIR}/services/${f}
            ${DOCKER_COMPOSE} stop
            cd ${ROOT_DIR}/services
        fi
    fi
    done

    ${DOCKER_COMPOSE} stop
}

function system-stop() {
    system:stop
}

