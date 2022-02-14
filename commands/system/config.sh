## Print dde system config
#
# Command
#    system:config
#    s:c

function system:config() {
    cd ${ROOT_DIR}
    docker-compose config
}

function s:c() {
    system:config
}

