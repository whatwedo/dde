_serviceRunning() {
    local running=0
    if [ -z `docker ps -q --no-trunc | grep $(${DOCKER_COMPOSE} ps -q ${1})` ]; then
        running=1
    fi

    return ${running}
}
