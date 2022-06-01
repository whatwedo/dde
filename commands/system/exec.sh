## open shell of container
#
# Command
#    system:exec <container>


function system:exec() {
    cd ${ROOT_DIR}
    docker-compose exec ${1} /bin/sh
}


