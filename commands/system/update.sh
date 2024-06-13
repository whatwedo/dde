## Update dde system
#
# Command
#    system:update
#    system-update

function system:update() {
    _logRed "Removing dde (system)"
    system:destroy

    _logYellow "Updating dde repository"
    cd ${ROOT_DIR}
    branch=$(git rev-parse --abbrev-ref HEAD)
    if [ $branch != 'master' ] && [ $branch != 'main' ]
    then
        _logYellow "Be careful! You have not checked out the stable branch for dde. You're currently on: $branch"
    fi
    git pull

    _logYellow "Updating docker images"
    cd ${ROOT_DIR}
    ${DOCKER_COMPOSE} pull
    docker pull mikefarah/yq
    ${DOCKER_COMPOSE} build --pull


    cd services/conf.d

    for f in *; do
        if [ -f ${ROOT_DIR}/services/${f}/docker-compose.yml ]; then
            _logYellow "Update service ${f}"
            cd ${ROOT_DIR}/services/${f}
            ${DOCKER_COMPOSE} pull
            ${DOCKER_COMPOSE} build --pull
        fi
    done

    cd ${ROOT_DIR}

    _ddeCheckNetwork

    system:services:update dnsmasq
    system:services:enable dnsmasq
    system:services:update mailcrab
    system:services:enable mailcrad
    system:services:update mariadb
    system:services:enable mariadb
    system:services:update reverseproxy
    system:services:enable reverseproxy

    _logYellow "Starting dde (system)"
    system:up

    _logGreen "Finished update successfully"
}

function system-update() {
    system:update
}
