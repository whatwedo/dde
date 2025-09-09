## Remove system dde infrastructure
#
# Command
#    system:destroy
#    system-destroy

function system:destroy() {
    if [ -z $(${DOCKER_BIN} network ls --filter=name=${NETWORK_NAME} -q) ]; then
        _logRed "Already destroyed"
        return 0
    fi

    _logRed "Removing containers"
    ${DOCKER_COMPOSE} --project-directory ${ROOT_DIR} down --remove-orphans
    ${DOCKER_BIN} rm -f $(${DOCKER_BIN} network inspect -f '{{ range $key, $value := .Containers }}{{ printf "%s\n" $key }}{{ end }}' ${NETWORK_NAME}) &>/dev/null || true

    _logRed "Remove network ${NETWORK_NAME}"
    ${DOCKER_BIN} network rm ${NETWORK_NAME}

    _logGreen "Finished destroying successfully"
}


function system-destroy() {
    system:destroy
}
