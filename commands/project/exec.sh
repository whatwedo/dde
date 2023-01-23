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
# dde prohect:exec make install      # run make install


function project:exec() {
    _checkProject

    local _service=$(docker run --rm -v $(pwd):/workdir mikefarah/yq:3 yq r --printMode p docker-compose.yml 'services.*' | head -n1 | sed 's/.*\.//')
    local _command=()
    while [[ ${#} -gt 0 ]]; do
        ## Stop parsing after the first non-flag argument
        #if [[ ${1} != -* ]]; then
        #  break
        #fi
        ## Help message
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


    echo _command::${_command[@]}
    echo _service::${_service}


    local _test_doas=$(${DOCKER_COMPOSE} exec ${_service} sh -c "if [[ -f /usr/bin/doas ]]; then echo 0; else echo 1; fi")

    if [[ "${_test_doas}" == "0" ]]; then
        echo 'doas'
        ${DOCKER_COMPOSE} exec ${_service} doas -u dde ${_command[@]}
    else
        ${DOCKER_COMPOSE} exec ${_service} gosu dde ${_command[@]}
    fi
}


function exec() {
    project:exec ${@}
}
