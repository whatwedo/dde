_serviceExists() {
    ${DOCKER_COMPOSE} ps --services | grep -q "^${1}$" && return 0 || return 1
}

_existingServices() {
    ${DOCKER_COMPOSE} ps --services
}
