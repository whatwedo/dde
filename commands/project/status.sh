## Print project status

function project:status() {
    _checkProject
    docker-compose ps
}
