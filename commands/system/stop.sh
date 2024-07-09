## Start already created system dde environment
#
# Command
#    system:stop
#    system-stop

function system:stop() {
    cd ${ROOT_DIR}

    _logGreen "Stopping System services"

    for service in $(_getYamlServices); do
        _logGreen "Stopping service ${service}"
        # Will not run if no directories are available
        if [ -d "${ROOT_DIR}/services/${service}" ]; then
            _logGreen "Stopping service ${service}"
            ${DOCKER_COMPOSE} --project-directory ${ROOT_DIR}/services/${service} stop
        else
            _logRed "docker-compose.yml for Service ${service} not found"
        fi
    done
}

function system-stop() {
    system:stop
}

