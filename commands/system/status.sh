## Print dde system status
#
# Command
#    system:status
#    s:s

function system:status() {
    cd ${ROOT_DIR}
    docker-compose ps
}

function s:s() {
    system:status
}

