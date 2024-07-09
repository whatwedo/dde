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


    if [ $(_existsYamlService ${1}) == "true" ]; then
        _logYellow "Disable System service: ${1}"
        _removeYamlService ${1}
        ${DOCKER_COMPOSE} --project-directory ${ROOT_DIR}/services/${1} stop
    else
        _logYellow "Service not persent in ${DDE_CONFIG_FILE}: ${1}"
    fi

}
