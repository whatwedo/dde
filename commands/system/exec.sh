## open shell of container
#
# Command
#    system:exec <container>


function system:exec() {
    cd ${ROOT_DIR}
    ${DOCKER_COMPOSE} exec ${1} /bin/sh
}


