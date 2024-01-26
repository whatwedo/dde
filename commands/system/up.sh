## Initializes and starts dde system infrastructure
#
# Command
#    system:up
#    system-up

function system:up() {
    _ddeCheckUpdate

    _logYellow "Creating network if required"
    local networks=$(docker network inspect dde)
    if [ "$(docker network ls --filter=name=${NETWORK_NAME} -q)" == "" ]; then
        _logGreen "Create network ${NETWORK_NAME}"
        docker network create ${NETWORK_NAME}
    fi

    _logYellow "Creating default docker config.json"
    if [ ! -f ~/.docker/config.json ]; then
        mkdir -p ~/.docker
        echo '{}' >~/.docker/config.json
    fi

    _logYellow "Creating CA cert if required"
    mkdir -p ${DDE_CERT_PATH}
    cd ${DDE_CERT_PATH}
    if [ ! -f ca.pem ]; then
        openssl genrsa -out ca.key 2048
        openssl req -x509 -new -nodes -key ca.key -sha256 -days 3650 -subj "/C=CH/ST=Bern/L=Bern/O=dde/CN=dde" -out ca.pem
    fi

    _logYellow "Creating certs used by system services"
    ${HELPER_DIR}/generate-vhost-cert.sh ${DDE_CERT_PATH} mailhog.test

    _logYellow "Starting containers"
    cd ${ROOT_DIR}

    ${DOCKER_COMPOSE} up -d

    _addSshKey

    _logGreen "Finished startup successfully"
}

function system-up() {
    system:up
}
