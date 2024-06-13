## Start dde system service
#
# Command
#    system:services:start <service>

function system:services:enable() {
    cd ${ROOT_DIR}

    if _serviceExists ${1}; then
        _logGreen "System service: ${1} found"
    else
        _logRed "System service: ${1} not found"
        return
    fi

    _logYellow "Enable System services: ${1}"

    ${DOCKER_COMPOSE} -f services/${1}/docker-compose.yml up -d
}
