## Show log output
#
# Command
#    project:log
#    p:l
#    log

function project:log() {
    _checkProject
    docker-compose logs -f --tail=100
}

function p:l() {
    project:log
}

function log() {
    project:log
}
