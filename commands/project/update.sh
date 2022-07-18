## Update/rebuild project
#
# Command
#    project:update
#    update

function project:update() {
    _checkProject

    _logRed "Destroying project"
    project:destroy

    _logYellow " Pulling/building images"
    ${DOCKER_COMPOSE} build --pull
    ${DOCKER_COMPOSE} pull

    _logYellow "Starting project"
    project:up

    _logGreen "Finished update successfully"
}

function update() {
    project:update
}
