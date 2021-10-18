## Start already created system dde environment
#
# Command
#    system:start

function system:start() {
    cd ${ROOT_DIR}
    docker-compose start
    _addSshKey
}

