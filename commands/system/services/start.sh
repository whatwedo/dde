## Start dde system service
#
# Command
#    system:services:start <service>

function system:services:start() {
    cd ${ROOT_DIR}

    if _serviceExists ${1}; then
        _logGreen "System service: ${1} found"
    else
        _logRed "System service: ${1} not found"
        return
    fi

    ${DOCKER_COMPOSE} --project-directory ${ROOT_DIR}/services/${1} up -d
}
