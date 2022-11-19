## Opens shell with user dde on first container
#
# Command
#    project:exec
#    p:e
#    exec

# Arguments
#    service       optional, open shell of service, default open first container
function project:exec() {
    _checkProject
    local service=$(${DOCKER_BIN} run --rm -v $(pwd):/workdir mikefarah/yq:3 yq r --printMode p docker-compose.yml 'services.*' | head -n1 | sed 's/.*\.//')

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

    ${DOCKER_COMPOSE} exec ${service} /bin/sh -c "if [ -f /usr/bin/doas ]; then doas -u dde sh; else gosu dde sh; fi"
}

function p:e() {
    project:exec ${1}
}

function exec() {
    project:exec ${1}
}
