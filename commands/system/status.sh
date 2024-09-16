## Print dde system status
#
# Command
#    system:status
#    s:s
#    system-status

function system:status() {
    cd ${ROOT_DIR}

    for service in $(_getYamlServices); do
        if [ -d "${ROOT_DIR}/services/${service}" ]; then
            _logGreen "Status of service ${service}"
            ${DOCKER_COMPOSE} --project-directory ${ROOT_DIR}/services/${service} ps
        fi
    done
}

function s:s() {
    system:status
}

function system-status() {
    system:status
}
