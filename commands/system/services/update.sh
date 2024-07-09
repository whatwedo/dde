## update dde system service
#
# Command
#    system:services:update <service>

function system:services:update() {
    cd ${ROOT_DIR}

    if _serviceExists ${1}; then
        _logGreen "System service: ${1} found"
    else
        _logRed "System service: ${1} not found"
        return
    fi

    _logYellow "Enable System services: ${1}"

    ${DOCKER_COMPOSE} --project-directory ${ROOT_DIR}/services/${1} stop
    ${DOCKER_COMPOSE} --project-directory ${ROOT_DIR}/services/${1} pull
    ${DOCKER_COMPOSE} --project-directory ${ROOT_DIR}/services/${1} up -d


}
