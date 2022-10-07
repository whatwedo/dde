_serviceRunning() {
    local running=1
    if [ -z `docker ps -q --no-trunc | grep $(${DOCKER_COMPOSE} ps -q ${1})` ]; then
        running=0
    fi

    return ${running}
}
