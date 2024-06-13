## Disable dde system service
#
# Command
#    system:services:disable <service>

function system:services:disable() {
    cd ${ROOT_DIR}

    if _serviceExists ${1}; then
        _logGreen "System service: ${1} found"
    else
        _logRed "System service: ${1} not found"
        return
    fi

    _logYellow "Disable System services: ${1}"
    rm -f services/conf.d/${1}
    ${DOCKER_COMPOSE} -f services/${1}/docker-compose.yml stop
}
