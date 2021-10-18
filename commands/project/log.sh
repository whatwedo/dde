## Show log output
#
# Command
#    project:log
#    p:l

function project:log() {
    _checkProject
    docker-compose logs -f --tail=100
}

function p:l() {
    project:log
}
