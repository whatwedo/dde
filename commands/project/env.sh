## Show system environment
#
# Command
#    project:env
#    p:env

function project:env() {
    _checkProject
    system:env

    _logGreen "DDE Project env:"
    if [ -f ./docker-compose.override.yml ]; then
        echo ./docker-compose.override.yml defined
    fi
    echo SYNC_MODE=${SYNC_MODE}

}

function p:env() {
    project:env
}
