_getContainerShell() {
    local service=$1
    local user=$2

    # If DDE_CONTAINER_SHELL is set, use it
    if [[ -n "${DDE_CONTAINER_SHELL}" ]]; then
        echo "/bin/${DDE_CONTAINER_SHELL}"
        return 0
    fi

    # Detect the default shell from the container's passwd file
    local shell=$(${DOCKER_COMPOSE} exec --user ${user} ${service} sh -c 'getent passwd $(whoami) | cut -d: -f7' 2>/dev/null | tr -d '\r\n')

    # If detection failed or returned empty, fallback to sh
    if [[ -z "${shell}" ]]; then
        echo "sh"
    else
        echo "${shell}"
    fi
}
