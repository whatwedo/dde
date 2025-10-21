## Initializes and starts dde system infrastructure
#
# Command
#    system:up
#    system-up

function system:up() {
    _ddeCheckUpdate

    _ensureNetwork

    _logYellow "Creating default docker config.json"
    if [ ! -f ~/.docker/config.json ]; then
        mkdir -p ~/.docker
        echo '{}' >~/.docker/config.json
    fi

    _logYellow "Creating CA cert if required"
    mkdir -p ${CERT_DIR}
    cd ${CERT_DIR}
    if [ ! -f ca.pem ]; then
        openssl genrsa -out ca.key 2048
        openssl req -x509 -new -nodes -key ca.key -sha256 -days 3650 -subj "/C=CH/ST=Bern/L=Bern/O=dde/CN=dde" -out ca.pem
    fi

    _logYellow "Creating certs used by system services"
    ${HELPER_DIR}/generate-vhost-cert.sh ${CERT_DIR} mailcrab.test

    _logYellow "Starting containers"
    cd ${ROOT_DIR}

    ${DOCKER_COMPOSE} up -d

    _addSshKey

    _logGreen "Finished startup successfully"
}

function system-up() {
    system:up
}
