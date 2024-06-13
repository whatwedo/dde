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



    _getServices allServices
    for service in "${allServices[@]}"; do
       if _serviceEnabled ${service}; then
           _logYellow "Update system service ${service}"
            ${DOCKER_COMPOSE} -f services/${service}/docker-compose.yml pull
            ${DOCKER_COMPOSE} -f services/${service}/docker-compose.yml build --pull
       fi
        echo "$service"
    done

    cd ${ROOT_DIR}

    _ddeCheckNetwork

    _logYellow "Starting dde (system)"
    system:up

    _logGreen "Finished update successfully"
}

function system-update() {
    system:update
}
