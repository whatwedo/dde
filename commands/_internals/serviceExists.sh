_serviceExists() {
    local inArrayFound=1
    for service in $(docker-compose ps --services)
    do
        if [[ "${1}" == "${service}" ]]; then
            inArrayFound=0
        fi
    done

    return ${inArrayFound}
}

_existingServices() {
    ${DOCKER_COMPOSE} ps --services
}
