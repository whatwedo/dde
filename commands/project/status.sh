## Print project status
#
# Command
#    project:status

function project:status() {
    _checkProject
    docker-compose ps
}
