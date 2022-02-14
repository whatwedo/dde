## Show system environment
#
# Command
#    project:env
#    p:env

function project:env() {
    _checkProject
    local oldPwd=$(pwd)
    system:env
    cd ${oldPwd}

    echo ""
    _logGreen "DDE Project env:"
    if [ -f ./docker-compose.override.yml ]; then
        echo ./docker-compose.override.yml defined
    fi
    echo SYNC_MODE=${SYNC_MODE}


    echo ""
    _logYellow "Project services"
    docker-compose ps --services

}

function p:env() {
    project:env
}
