## Update/rebuild project

function project:update() {
    _checkProject

    _logRed "Destroying project"
    project:destroy

    _logYellow " Pulling/building images"
    docker-compose build --pull
    docker-compose pull

    _logYellow "Starting project"
    projectup

    _logGreen "Finished update successfully"
}
