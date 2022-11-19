_serviceIsRunning() {
    local isRunning=0
    if [ -z `${DOCKER_BIN} ps -q --no-trunc | grep $(${DOCKER_COMPOSE} ps -q ${1})` ]; then
        isRunning=1
    fi

    return ${isRunning}
}
