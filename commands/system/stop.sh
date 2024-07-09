## Start already created system dde environment
#
# Command
#    system:stop
#    system-stop

function system:stop() {
    cd ${ROOT_DIR}

    _logGreen "Stopping System services"

    for service in $(_getYamlServices); do
        if [ -d "$service" ]; then
            # Will not run if no directories are available
            if [ -f "${ROOT_DIR}/${service}/docker-compose.yml" ]; then
                _logGreen "Stopping service ${service}"
                ${DOCKER_COMPOSE} --project-directory ${ROOT_DIR}/services/${service} stop
            else
                _logRed "docker-compose.yml for Service ${service} not found"
            fi
        fi
    done
}

function system-stop() {
    system:stop
}

