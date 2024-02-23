## Opens privileged shell on first container
#
# Command
#    project:shell:root
#    s:e:r
#    shell-root

function project:shell:root() {
    _checkProject
    local service=$(docker run --rm -v $(pwd):/workdir mikefarah/yq:3 yq r --printMode p docker-compose.yml 'services.*' | head -n1 | sed 's/.*\.//')

    if [[ "${1}" != "" ]]; then
        if _serviceExists ${1}; then
            service=${1}
        else
            _logRed "service ${1} does not exists in project"
            _existingServices
            return 1
        fi
    fi

    if ! _serviceIsRunning ${service}; then
        _logYellow "Project is not running. Starting ..."
        project:up
    fi

    ${DOCKER_COMPOSE} exec ${service} /bin/${DDE_CONTAINER_SHELL} -c "sh"
}

function p:s:r() {
    project:shell:root ${1}
}

function shell:root() {
    project:shell:root ${1}
}
