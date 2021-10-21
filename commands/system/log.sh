## Show log output of system services
#
# Command
#    system:log <service>
#    system-log <service>
#    system-logs <service>
#    system:logs <service>
#
# Arguments:
#    <service>    optional, name of service to show the logs, default shows logs of all containers

function system:log() {
    cd ${ROOT_DIR}
    docker-compose logs -f --tail=100 ${1}
}


function system-log() {
    system:log
}

function system-logs() {
    system:log
}

function system:logs() {
    system:log
}
