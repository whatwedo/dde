## Remove system dde infrastructure
#
# Command
#    system:destroy
#    system-destroy

function system:destroy() {
    _logRed "Removing containers"
    ${DOCKER_BIN} rm -f $(${DOCKER_BIN} network inspect -f '{{ range $key, $value := .Containers }}{{ printf "%s\n" $key }}{{ end }}' ${NETWORK_NAME}) &>/dev/null
    cd ${ROOT_DIR}
    ${DOCKER_COMPOSE} down --remove-orphans

    _logRed "Removing network if created"
    if [ "$(${DOCKER_BIN} network ls --filter=name=${NETWORK_NAME} -q)" != "" ]; then
        _logRed "Remove network ${NETWORK_NAME}"
        ${DOCKER_BIN} network rm ${NETWORK_NAME}
    fi

    _logGreen "Finished destroying successfully"
}


function system-destroy() {
    system:destroy
}
