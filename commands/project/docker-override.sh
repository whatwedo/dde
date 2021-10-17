## creates  docker-compose.override.yml in your project

function project:docker-override() {
    _checkProject
    if [ -f ./docker-compose.override.yml ]; then
        _logRed "docker-compose.override.yml already installed"
    else
        cp ${ROOT_DIR}/docker-compose.override.yml .
        _logGreen "docker-compose.override.yml installed, make your custom changes"
    fi

}
