## Opens execute command with user dde on first container
#
# Command
#    project:exec [option]... command args
#    exec
#
# Options:
# --service <service>, -s <service>     execute the command on given service
#                                       if not provides the first service in the docker-compose will be taken.
#
# ex:
# dde project:exec -s web yarn watch # run yarn watch on the "web" service
# dde project:exec make install      # run make install


function project:exec() {
    _checkProject

    local _service=$(docker run --rm -v $(pwd):/workdir mikefarah/yq:3 yq r --printMode p docker-compose.yml 'services.*' | head -n1 | sed 's/.*\.//')
    local _command=()
    while [[ ${#} -gt 0 ]]; do
        if [[ ${1} == '--service' || ${1} == '-s' ]]; then
            echo "service is ${2}"
            _service=${2}
            shift 1
            shift 1
            continue
        fi
        _command+=("${1}")
        shift
        ## Append unclassified flags back to runner_args
    done

    if [[ ${_command[@]} == '' ]]; then
        _command+=("sh")
    fi

    if [[ "$(_isDoas ${_service})" == "1" ]]; then
        ${DOCKER_COMPOSE} exec ${_service} doas -u dde ${_command[@]}
    else
        ${DOCKER_COMPOSE} exec ${_service} gosu dde ${_command[@]}
    fi
}

function exec() {
    project:exec ${@}
}
