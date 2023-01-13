## Enable dde system service
#
# Command
#    system:services:enable <service>

function system:services:enable() {
    cd ${ROOT_DIR}
    _logGreen "Enable System services: ${1}"
    cd services

    for f in *; do
    if [ -d "$f" ]; then
        # Will not run if no directories are available
        if [ -f "${f}/docker-compose.yml" ]; then
            if [ "$f" == "${1}" ]; then
                touch conf.d/${f}
                cd ${f}
                ${DOCKER_COMPOSE} up -d
            fi
        fi
    fi
done
}
