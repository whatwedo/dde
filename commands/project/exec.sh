## Executes commands as 'dde' in a given or the first composer file service container.
#
#
# Command
#    project:exec
#    p:e
#    exec

# Arguments
#    service       optional, open shell of service, default open first container
function project:exec() {
    _logYellow "Please note that the command exec has been renamed to 'shell' in the script."

    _checkProject
    # Determine the first service from composer file
    service="$(${DOCKER_COMPOSE} config | _yq_stdin e '.services | keys | .[0]')"

    # Check if the provided service exists
    if _serviceExists "${1}"; then
        service="${1}" # Override service name if it exists
        shift # Remove the first argument since it's the service name
    fi

    # Prepare the command, independent of the condition
    cmd=$(printf "%q " "$@")

    # Execute the command in the service container
    ${DOCKER_COMPOSE} exec ${service} /bin/${DDE_CONTAINER_SHELL} -c "doas -u dde ${cmd}"

}

function p:e() {
    project:exec ${@}
}

function exec() {
    project:exec ${@}
}
