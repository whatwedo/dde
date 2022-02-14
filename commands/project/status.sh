## Print project status
#
# Command
#    project:status
#    status

function project:status() {
    _checkProject
    docker-compose ps
}

function status() {
    project:status
}
