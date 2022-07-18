## Print dde system status
#
# Command
#    system:status
#    s:s
#    system-status

function system:status() {
    cd ${ROOT_DIR}
    ${DOCKER_COMPOSE} ps
}

function s:s() {
    system:status
}

function system-status() {
    system:status
}
