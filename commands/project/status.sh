## Print project status
#
# Command
#    project:status
#    status

function project:status() {
    _checkProject
    ${DOCKER_COMPOSE} ps
}

function status() {
    project:status
}
