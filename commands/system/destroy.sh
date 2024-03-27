## Remove system dde infrastructure
#
# Command
#    system:destroy
#    system-destroy

function system:destroy() {
    _logRed "Removing containers"
    docker rm -f $(docker network inspect -f '{{ range $key, $value := .Containers }}{{ printf "%s\n" $key }}{{ end }}' ${NETWORK_NAME}) &>/dev/null
    cd ${ROOT_DIR}
    ${DOCKER_COMPOSE} down --remove-orphans

    cd services/conf.d

    for f in *; do
        if [ -f ${ROOT_DIR}/services/${f}/docker-compose.yml ]; then
            _logGreen "Starting service ${f}"
            cd ${ROOT_DIR}/services/${f}
            ${DOCKER_COMPOSE} down --remove-orphans
        fi
    done

    cd ${ROOT_DIR}


    _logRed "Removing network if created"
    if [ "$(docker network ls --filter=name=${NETWORK_NAME} -q)" != "" ]; then
        _logRed "Remove network ${NETWORK_NAME}"
        docker network rm ${NETWORK_NAME}
    fi

    _logGreen "Finished destroying successfully"
}


function system-destroy() {
    system:destroy
}
