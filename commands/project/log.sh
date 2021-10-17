## Show log output

function project:log() {
    _checkProject
    docker-compose logs -f --tail=100
}
