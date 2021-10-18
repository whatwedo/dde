## Show log output of system services
#
# Command
#    system:log

function system:log() {
    cd ${ROOT_DIR}
    docker-compose logs -f --tail=100
}
