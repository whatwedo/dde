## Remove central project infrastructure
#
# Command
#    project:destroy
#    destroy

function project:destroy() {
    _checkProject

    _logYellow "Removing containers"
    ${DOCKER_COMPOSE} down --remove-orphans

    _logYellow "Deleting SSL certs"

    for vhost in $(${DOCKER_COMPOSE} config | _yq_stdin e '.services.*.environment.VIRTUAL_HOST | select(length>0)'); do
        IFS=',' read -ra hosts <<< "$vhost"
        for host in "${hosts[@]}"; do
            _logYellow "Delete certs for ${host}"
            rm -f ${CERT_DIR}/${host}.*
        done
    done

    _logGreen "Finished destroying successfully"
}

function destroy() {
    project:destroy
}

