## Start already created system dde environment
#
# Command
#    system:stop <service>
#    system-stop

function system:stop() {
    cd ${ROOT_DIR}

    local service=""
    if [[ "${1}" != "" ]]; then
        if _serviceExists ${1}; then
            service=${1}
        else
            _logRed "service ${1} does not exists in dde system"
            _existingServices
            return 1
        fi
    fi

    if [[ "${service}" != "" ]]; then
        if ! _serviceIsRunning ${service}; then
            _logRed "Service ${service} is not running."
        fi
    fi
    ${DOCKER_COMPOSE} stop ${service}

}

function system-stop() {
    system:stop
}

