## Show log output
#
# Command
#    project:log
#    p:l
#    log
#
# Arguments
#    service       optional, show logs of service, default shows all
function project:log() {
    _checkProject
    local service=""
    if [[ "${1}" != "" ]]; then
        if _serviceExists ${1}; then
            service=${1}
        else
            _logRed "service ${1} does not exists in project"
            _existingServices
            return 1
        fi
    fi

    ${DOCKER_COMPOSE} logs -f --tail=100 ${service}
}

function p:l() {
    project:log
}

function log() {
    project:log
}
