## Show log output of system services
#
# Command
#    system:log <service>
#    system-log <service>
#    system-logs <service>
#    system:logs <service>
#
# Arguments:
#    <service>    optional, name of service to show the logs, default shows logs of all containers

function system:log() {
    cd ${ROOT_DIR}

    local service=""
    if [[ "${1}" != "" ]]; then
        if _serviceExists ${1}; then
            service=${1}
        else
            _logRed "service ${1} does not exists"
            _existingServices
            return 1
        fi
    fi

    docker-compose logs -f --tail=100 ${service}
}


function system-log() {
    system:log
}

function system-logs() {
    system:log
}

function system:logs() {
    system:log
}
