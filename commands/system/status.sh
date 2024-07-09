## Print dde system status
#
# Command
#    system:status
#    s:s
#    system-status

function system:status() {
    cd ${ROOT_DIR}

    cd services/conf.d

    for f in *; do
        if [ -f "${ROOT_DIR}/services/${f}/docker-compose.yml" ]; then
            _logGreen "Status of service ${f}"
            ${DOCKER_COMPOSE} -f ${ROOT_DIR}/services/${f}/docker-compose.yml ps
        fi
    done
}

function s:s() {
    system:status
}

function system-status() {
    system:status
}
